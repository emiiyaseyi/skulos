<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionAnswer;
use Illuminate\Support\Collection;

/**
 * Question import/export, matching the reference repo's own
 * questionimport.php / export_questions.php feature-for-feature.
 *
 * This is one of the pieces Moodle itself supplies to the reference
 * plugin (its `qformat_*` plugins parse Moodle XML, GIFT and Aiken).
 * SKULOS is not Moodle, so every parser here is native.
 *
 * IMPORT formats (5, matching the reference exactly):
 *  - csv          the reference's own column layout:
 *                 type,question,a1..a6,correct,marks,case_sensitive,marking_guide
 *                 `correct` is answer LETTERS (A / "A,C") for choice types,
 *                 pipe-separated text for shortanswer, true|false for truefalse.
 *  - gift         standard Moodle GIFT
 *  - aiken        standard Moodle Aiken ("A) ...", "ANSWER: B")
 *  - shortanswer  plain text: question, then "Answer:" lines, optional "Case:"/"Marks:"
 *  - essay        plain text: question, then "Mark:" and "Answer guide:"
 *
 * EXPORT formats: csv, moodlexml, gift, json.
 *
 * Every format also has a downloadable sample template (see
 * template()), so an admin never has to guess the layout.
 */
class QuestionPortabilityService
{
    public const IMPORT_FORMATS = [
        'csv' => 'CSV',
        'gift' => 'GIFT',
        'aiken' => 'Aiken',
        'shortanswer' => 'Short answer (plain text)',
        'essay' => 'Essay (plain text)',
        'moodlexml' => 'Moodle XML',
        'json' => 'JSON',
    ];

    public const EXPORT_FORMATS = [
        'csv' => 'CSV',
        'moodlexml' => 'Moodle XML',
        'gift' => 'GIFT',
        'json' => 'JSON',
    ];

    /** SKULOS type => Moodle/reference type on the wire. */
    protected const TO_MOODLE = [
        'multiple_choice_single' => 'multichoice',
        'multiple_choice_multiple' => 'multicheck',
        'true_false' => 'truefalse',
        'short_answer' => 'shortanswer',
        'essay' => 'essay',
        'matching' => 'matching',
    ];

    /** Example content shown as the paste box's placeholder, per format. */
    public const PASTE_PLACEHOLDERS = [
        'csv' => "type,question,a1,a2,a3,a4,correct,marks\nmultichoice,What is 2+2?,2,4,6,8,B,1\ntruefalse,The Earth is round.,,,,,true,1",
        'gift' => "What is 2+2? {\n  =4\n  ~2\n  ~6\n}",
        'aiken' => "What color is the sky?\nA) Red\nB) Blue\nC) Green\nANSWER: B",
        'shortanswer' => "Generally, an IPO is sold in the ______ Market.\nAnswer: Primary Market\nAnswer: PM\n\nMeristem has ____ business units\nAnswer: six\nAnswer: 6",
        'essay' => "Distinguish the money market and capital market with 2 examples.\nMark: 3\nAnswer guide: The money market trades short-term securities. The capital market trades long-term instruments.",
        'moodlexml' => "<?xml version=\"1.0\"?>\n<quiz>\n  <question type=\"truefalse\">\n    <questiontext><text>The Earth is round.</text></questiontext>\n    <answer fraction=\"100\"><text>true</text></answer>\n  </question>\n</quiz>",
        'json' => "[\n  {\n    \"question\": \"What is 2+2?\",\n    \"skulos_type\": \"multiple_choice_single\",\n    \"marks\": 1,\n    \"answers\": [\n      {\"text\": \"4\", \"fraction\": 1},\n      {\"text\": \"2\", \"fraction\": 0}\n    ]\n  }\n]",
    ];

    // ── Sample templates ────────────────────────────────────────────

    /** @return array{filename: string, mime: string, content: string} */
    public function template(string $format): array
    {
        $templates = [
            'csv' => [
                'filename' => 'skulos_csv_template.csv',
                'mime' => 'text/csv; charset=utf-8',
                'content' => "type,question,a1,a2,a3,a4,correct,marks,case_sensitive,marking_guide\r\n"
                    ."multichoice,What is the capital of France?,London,Berlin,Paris,Madrid,C,1,,\r\n"
                    ."multicheck,Which of these are prime numbers?,2,3,4,9,\"A,B\",2,,\r\n"
                    ."truefalse,The Earth orbits the Sun.,,,,,true,1,,\r\n"
                    ."shortanswer,Name the largest planet.,,,,,Jupiter|jupiter,2,no,\r\n"
                    ."shortanswer,Type the exact command to list files.,,,,,ls,1,yes,\r\n"
                    ."essay,Describe the water cycle.,,,,,,5,,Award marks for: evaporation condensation precipitation runoff.\r\n",
            ],
            'shortanswer' => [
                'filename' => 'skulos_shortanswer_template.txt',
                'mime' => 'text/plain; charset=utf-8',
                'content' => "Generally, an initial public offering (IPO) is sold in the ______ Market.\r\n"
                    ."Answer: Primary Market\r\n"
                    ."Answer: Market\r\n"
                    ."Answer: PM\r\n"
                    ."\r\n"
                    ."Meristem has ____ subsidiaries\r\n"
                    ."Answer: six\r\n"
                    ."Answer: 6\r\n"
                    ."\r\n"
                    ."Type the exact UNIX command to list files in a directory.\r\n"
                    ."Answer: ls\r\n"
                    ."Case: sensitive\r\n"
                    ."Marks: 2\r\n",
            ],
            'essay' => [
                'filename' => 'skulos_essay_template.txt',
                'mime' => 'text/plain; charset=utf-8',
                'content' => "Distinguish the money market and capital market with 2 examples each.\r\n"
                    ."Mark: 3\r\n"
                    ."Answer guide: The money market trades short-term securities (e.g. treasury bills, commercial papers). The capital market trades longer-term instruments (e.g. equities/shares, bonds).\r\n"
                    ."\r\n"
                    ."What are the four functions of money?\r\n"
                    ."Mark: 4\r\n"
                    ."Answer guide: 1. Medium of exchange. 2. Store of value. 3. Unit of account. 4. Standard of deferred payment.\r\n",
            ],
            'gift' => [
                'filename' => 'skulos_gift_template.txt',
                'mime' => 'text/plain; charset=utf-8',
                'content' => "::Q1:: What is 2+2? {\r\n"
                    ."  =4 # Correct!\r\n"
                    ."  ~2 # Wrong\r\n"
                    ."  ~6 # Wrong\r\n"
                    ."}\r\n"
                    ."\r\n"
                    ."The sky is blue. {TRUE}\r\n"
                    ."\r\n"
                    ."::Short:: Name a primary colour. {=red =blue =yellow}\r\n",
            ],
            'aiken' => [
                'filename' => 'skulos_aiken_template.txt',
                'mime' => 'text/plain; charset=utf-8',
                'content' => "What color is the sky?\r\n"
                    ."A) Red\r\n"
                    ."B) Blue\r\n"
                    ."C) Green\r\n"
                    ."D) Yellow\r\n"
                    ."ANSWER: B\r\n"
                    ."\r\n"
                    ."How many days in a week?\r\n"
                    ."A) 5\r\n"
                    ."B) 7\r\n"
                    ."C) 10\r\n"
                    ."ANSWER: B\r\n",
            ],
            'moodlexml' => [
                'filename' => 'skulos_moodlexml_template.xml',
                'mime' => 'application/xml',
                'content' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<quiz>\r\n"
                    ."  <question type=\"multichoice\">\r\n"
                    ."    <name><text>Capital of France</text></name>\r\n"
                    ."    <questiontext><text>What is the capital of France?</text></questiontext>\r\n"
                    ."    <defaultgrade>1</defaultgrade>\r\n"
                    ."    <answer fraction=\"0\"><text>London</text></answer>\r\n"
                    ."    <answer fraction=\"100\"><text>Paris</text></answer>\r\n"
                    ."  </question>\r\n</quiz>\r\n",
            ],
            'json' => [
                'filename' => 'skulos_json_template.json',
                'mime' => 'application/json',
                'content' => json_encode([[
                    'name' => 'Capital of France',
                    'type' => 'multichoice',
                    'skulos_type' => 'multiple_choice_single',
                    'question' => 'What is the capital of France?',
                    'marks' => 1,
                    'category' => 'Geography',
                    'answers' => [
                        ['text' => 'London', 'fraction' => 0, 'feedback' => null],
                        ['text' => 'Paris', 'fraction' => 1, 'feedback' => 'Correct.'],
                    ],
                ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        ];

        return $templates[$format] ?? $templates['csv'];
    }

    // ── Export ──────────────────────────────────────────────────────

    public function export(Collection $questions, string $format): string
    {
        return match ($format) {
            'csv' => $this->toCsv($questions),
            'moodlexml' => $this->toMoodleXml($questions),
            'gift' => $this->toGift($questions),
            'json' => $this->toJson($questions),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    public function filenameFor(string $format): string
    {
        return 'question-bank.'.match ($format) {
            'moodlexml' => 'xml',
            'gift' => 'txt',
            'json' => 'json',
            default => 'csv',
        };
    }

    public function mimeFor(string $format): string
    {
        return match ($format) {
            'moodlexml' => 'application/xml',
            'json' => 'application/json',
            'gift' => 'text/plain',
            default => 'text/csv',
        };
    }

    /** Same column layout the CSV importer accepts, so an export re-imports cleanly. */
    protected function toCsv(Collection $questions): string
    {
        $rows = [['type', 'question', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'correct', 'marks', 'case_sensitive', 'marking_guide', 'category']];

        foreach ($questions as $question) {
            $options = $question->answers->values();
            $row = [
                self::TO_MOODLE[$question->type] ?? $question->type,
                $question->prompt,
            ];

            // a1..a6 only carry real options for the choice types.
            $isChoice = in_array($question->type, ['multiple_choice_single', 'multiple_choice_multiple'], true);
            for ($i = 0; $i < 6; $i++) {
                $row[] = $isChoice ? (string) ($options[$i]->answer_text ?? '') : '';
            }

            $row[] = match ($question->type) {
                'true_false' => $options->firstWhere('answer_text', 'True')?->fraction > 0 ? 'true' : 'false',
                'short_answer' => $options->pluck('answer_text')->implode('|'),
                'matching' => $options->map(fn ($a) => $a->answer_text.'='.$a->feedback)->implode('|'),
                'essay' => '',
                // Correct options as letters, matching the reference.
                default => $options->filter(fn ($a) => $a->fraction > 0)
                    ->map(fn ($a) => chr(65 + $options->search(fn ($o) => $o->id === $a->id)))
                    ->implode(','),
            };

            $row[] = $question->points;
            $row[] = $question->case_sensitive ? 'yes' : 'no';
            $row[] = (string) $question->marking_guidance;
            $row[] = (string) $question->category?->name;

            $rows[] = $row;
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function toMoodleXml(Collection $questions): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><quiz/>');

        foreach ($questions as $question) {
            $node = $xml->addChild('question');
            $node->addAttribute('type', self::TO_MOODLE[$question->type] ?? $question->type);
            $node->addChild('name')->addChild('text', htmlspecialchars((string) ($question->name ?: mb_substr($question->prompt, 0, 60)), ENT_XML1));
            $node->addChild('questiontext')->addChild('text', htmlspecialchars((string) $question->prompt, ENT_XML1));
            $node->addChild('defaultgrade', (string) $question->points);
            if ($question->general_feedback) {
                $node->addChild('generalfeedback')->addChild('text', htmlspecialchars((string) $question->general_feedback, ENT_XML1));
            }

            foreach ($question->answers as $option) {
                $answer = $node->addChild('answer');
                $answer->addAttribute('fraction', (string) round($option->fraction * 100, 2));
                $answer->addChild('text', htmlspecialchars((string) $option->answer_text, ENT_XML1));
                if ($option->feedback) {
                    $answer->addChild('feedback')->addChild('text', htmlspecialchars((string) $option->feedback, ENT_XML1));
                }
            }
        }

        return $xml->asXML();
    }

    protected function toGift(Collection $questions): string
    {
        $out = [];

        foreach ($questions as $question) {
            $title = str_replace(['::', "\n"], ' ', (string) ($question->name ?: 'Q'.$question->id));
            $body = $this->escapeGift($question->prompt);

            $answers = match ($question->type) {
                'essay' => '{}',
                'short_answer' => '{'.$question->answers->map(fn ($a) => '='.$this->escapeGift($a->answer_text))->implode(' ').'}',
                'matching' => '{'.$question->answers->map(fn ($a) => '='.$this->escapeGift($a->answer_text).' -> '.$this->escapeGift((string) $a->feedback))->implode(' ').'}',
                'true_false' => '{'.($question->answers->firstWhere('answer_text', 'True')?->fraction > 0 ? 'T' : 'F').'}',
                default => '{'.$question->answers->map(fn ($a) => ($a->fraction > 0 ? '=' : '~').$this->escapeGift($a->answer_text))->implode(' ').'}',
            };

            $out[] = "::{$title}:: {$body} {$answers}";
        }

        return implode("\n\n", $out)."\n";
    }

    protected function escapeGift(?string $text): string
    {
        return str_replace(['~', '=', '#', '{', '}', ':'], ['\~', '\=', '\#', '\{', '\}', '\:'], (string) $text);
    }

    protected function toJson(Collection $questions): string
    {
        return json_encode($questions->map(fn (CbtQuestion $question) => [
            'name' => $question->name,
            'type' => self::TO_MOODLE[$question->type] ?? $question->type,
            'skulos_type' => $question->type,
            'question' => $question->prompt,
            'marks' => (float) $question->points,
            'category' => $question->category?->name,
            'shuffle_answers' => (bool) $question->shuffle_answers,
            'case_sensitive' => (bool) $question->case_sensitive,
            'general_feedback' => $question->general_feedback,
            'marking_guidance' => $question->marking_guidance,
            'answers' => $question->answers->map(fn (CbtQuestionAnswer $a) => [
                'text' => $a->answer_text,
                'fraction' => (float) $a->fraction,
                'feedback' => $a->feedback,
            ])->values(),
        ])->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── Import ──────────────────────────────────────────────────────

    /**
     * Parses a file into normalised question arrays. Returns
     * [questions, errors] — a malformed row is skipped and reported
     * rather than aborting the whole import, since a 500-question file
     * with two bad rows should still land the other 498.
     *
     * @return array{0: array<int, array>, 1: array<int, string>}
     */
    public function parse(string $contents, string $format): array
    {
        // Strip a UTF-8 BOM (Windows Notepad/Excel add one, and it would
        // otherwise corrupt the very first header column name) and
        // normalise CRLF/CR to bare LF, since every parser below splits
        // on \n or \n{2,} — a Windows-saved file would otherwise leave a
        // stray \r on the end of every value.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $contents = preg_replace('/\r\n?/', "\n", (string) $contents);

        return match ($format) {
            'csv' => $this->parseCsv($contents),
            'moodlexml' => $this->parseMoodleXml($contents),
            'gift' => $this->parseGift($contents),
            'aiken' => $this->parseAiken($contents),
            'shortanswer' => $this->parseShortAnswerText($contents),
            'essay' => $this->parseEssayText($contents),
            'json' => $this->parseJson($contents),
            default => [[], ['Unsupported import format.']],
        };
    }

    /** The reference's own CSV layout: type,question,a1..a6,correct,marks,case_sensitive,marking_guide. */
    protected function parseCsv(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle);
        if (! $header) {
            return [[], ['The file is empty.']];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        foreach (['type', 'question'] as $required) {
            if (! in_array($required, $header, true)) {
                return [[], ["Missing required column: {$required}"]];
            }
        }

        $questions = [];
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || ($row[0] ?? '') === '' && count(array_filter($row)) === 0) {
                continue; // blank line
            }
            if (str_starts_with(trim((string) ($row[0] ?? '')), '#')) {
                continue; // comment line
            }

            $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $prompt = trim((string) ($data['question'] ?? ''));
            if ($prompt === '') {
                $errors[] = "Row {$line}: empty question text.";
                continue;
            }

            $moodleType = strtolower(trim((string) ($data['type'] ?? 'multichoice')));
            $correctRaw = trim((string) ($data['correct'] ?? ''));

            // a1..a6 are the option columns, blank ones skipped.
            $options = [];
            for ($i = 1; $i <= 6; $i++) {
                $text = trim((string) ($data['a'.$i] ?? ''));
                if ($text !== '') {
                    $options[] = $text;
                }
            }

            $type = $this->skulosType($moodleType, $correctRaw, substr_count($correctRaw, ',') + 1);

            $questions[] = [
                'name' => null,
                'type' => $type,
                'prompt' => $prompt,
                'points' => is_numeric($data['marks'] ?? null) ? (float) $data['marks'] : 1,
                'category' => trim((string) ($data['category'] ?? '')) ?: null,
                'case_sensitive' => strtolower(trim((string) ($data['case_sensitive'] ?? 'no'))) === 'yes',
                'general_feedback' => $data['feedback'] ?? null,
                'marking_guidance' => trim((string) ($data['marking_guide'] ?? $data['markingguidance'] ?? '')) ?: null,
                'answers' => $this->csvAnswers($type, $options, $correctRaw),
            ];
        }
        fclose($handle);

        return [$questions, $errors];
    }

    /** `correct` means answer LETTERS for choice types, pipe-separated text for shortanswer, true|false for truefalse. */
    protected function csvAnswers(string $type, array $options, string $correctRaw): array
    {
        if ($type === 'essay') {
            return [];
        }

        if ($type === 'true_false') {
            $isTrue = strtolower(trim($correctRaw)) !== 'false';

            return [
                ['answer_text' => 'True', 'fraction' => $isTrue ? 1 : 0, 'feedback' => null],
                ['answer_text' => 'False', 'fraction' => $isTrue ? 0 : 1, 'feedback' => null],
            ];
        }

        if ($type === 'short_answer') {
            return collect(explode('|', $correctRaw))
                ->map(fn ($text) => trim($text))
                ->filter(fn ($text) => $text !== '')
                ->map(fn ($text) => ['answer_text' => $text, 'fraction' => 1, 'feedback' => null])
                ->values()->all();
        }

        if ($type === 'matching') {
            return collect(explode('|', $correctRaw))->map(function ($pair) {
                [$stem, $match] = array_pad(explode('=', $pair, 2), 2, null);

                return ['answer_text' => trim((string) $stem), 'fraction' => 1, 'feedback' => trim((string) $match)];
            })->filter(fn ($row) => $row['answer_text'] !== '')->values()->all();
        }

        $letters = array_filter(array_map('trim', explode(',', strtoupper($correctRaw))));
        $correctIndexes = array_map(fn ($letter) => ord($letter) - 65, $letters);
        $share = max(1, count($correctIndexes));

        return collect($options)->map(fn ($text, $index) => [
            'answer_text' => $text,
            'fraction' => in_array($index, $correctIndexes, true)
                ? ($type === 'multiple_choice_multiple' ? round(1 / $share, 5) : 1)
                : 0,
            'feedback' => null,
        ])->values()->all();
    }

    protected function parseMoodleXml(string $contents): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [[], ['That file is not valid XML.']];
        }

        $questions = [];
        $errors = [];

        foreach ($xml->question as $index => $node) {
            $moodleType = (string) $node['type'];
            if ($moodleType === 'category') {
                continue; // Moodle emits a pseudo-question to mark a category
            }

            $prompt = trim((string) ($node->questiontext->text ?? ''));
            if ($prompt === '') {
                $errors[] = 'Question '.($index + 1).': no question text.';
                continue;
            }

            $options = [];
            $correct = [];
            foreach ($node->answer as $answer) {
                $text = trim((string) $answer->text);
                $options[] = $text;
                if ((float) $answer['fraction'] > 0) {
                    $correct[] = $text;
                }
            }

            $type = $this->skulosType($moodleType, implode('|', $correct), count($correct));

            $questions[] = [
                'name' => trim((string) ($node->name->text ?? '')) ?: null,
                'type' => $type,
                'prompt' => strip_tags($prompt),
                'points' => is_numeric((string) $node->defaultgrade) ? (float) $node->defaultgrade : 1,
                'category' => null,
                'general_feedback' => trim((string) ($node->generalfeedback->text ?? '')) ?: null,
                'answers' => $this->answersFrom($type, $options, $correct),
            ];
        }

        return [$questions, $errors];
    }

    /** GIFT: ::title:: text {=right ~wrong} / {T} / {F}, with optional "# feedback" per option. */
    protected function parseGift(string $contents): array
    {
        $contents = preg_replace('/^\/\/.*$/m', '', $contents);
        $questions = [];
        $errors = [];
        $blocks = preg_split('/\n\s*\n/', trim((string) $contents)) ?: [];

        foreach ($blocks as $index => $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $name = null;
            if (preg_match('/^::(.*?)::\s*(.*)$/s', $block, $m)) {
                $name = trim($m[1]);
                $block = trim($m[2]);
            }

            if (! preg_match('/^(.*?)\{(.*)\}\s*$/s', $block, $m)) {
                $errors[] = 'Block '.($index + 1).': no answer braces found.';
                continue;
            }

            $prompt = trim($m[1]);
            $answerBody = trim($m[2]);
            if ($prompt === '') {
                $errors[] = 'Block '.($index + 1).': no question text.';
                continue;
            }

            if ($answerBody === '') {
                $questions[] = $this->giftQuestion($name, 'essay', $prompt, []);
                continue;
            }

            if (in_array(strtoupper($answerBody), ['T', 'TRUE', 'F', 'FALSE'], true)) {
                $isTrue = in_array(strtoupper($answerBody), ['T', 'TRUE'], true);
                $questions[] = $this->giftQuestion($name, 'true_false', $prompt, [
                    ['answer_text' => 'True', 'fraction' => $isTrue ? 1 : 0, 'feedback' => null],
                    ['answer_text' => 'False', 'fraction' => $isTrue ? 0 : 1, 'feedback' => null],
                ]);
                continue;
            }

            $answers = [];
            $correctCount = 0;
            preg_match_all('/([=~])([^=~]+)/', $answerBody, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $raw = trim($match[2]);
                $feedback = null;
                if (str_contains($raw, '#')) {
                    [$raw, $feedback] = array_map('trim', explode('#', $raw, 2));
                }
                $text = $this->unescapeGift($raw);
                $isCorrect = $match[1] === '=';
                if ($isCorrect) {
                    $correctCount++;
                }
                $answers[] = ['answer_text' => $text, 'fraction' => $isCorrect ? 1 : 0, 'feedback' => $feedback ?: null];
            }

            // Every option correct means it is a short-answer accept-list,
            // not a multiple choice — the same call the reference makes.
            $type = ($correctCount === count($answers) && $correctCount > 0) ? 'short_answer' : ($correctCount > 1 ? 'multiple_choice_multiple' : 'multiple_choice_single');

            if ($type === 'multiple_choice_multiple') {
                $share = max(1, $correctCount);
                $answers = array_map(function ($a) use ($share) {
                    $a['fraction'] = $a['fraction'] > 0 ? round(1 / $share, 5) : 0;

                    return $a;
                }, $answers);
            }

            $questions[] = $this->giftQuestion($name, $type, $prompt, $answers);
        }

        return [$questions, $errors];
    }

    protected function giftQuestion(?string $name, string $type, string $prompt, array $answers): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'prompt' => $this->unescapeGift($prompt),
            'points' => 1,
            'category' => null,
            'answers' => $answers,
        ];
    }

    protected function unescapeGift(string $text): string
    {
        return trim(str_replace(['\~', '\=', '\#', '\{', '\}', '\:'], ['~', '=', '#', '{', '}', ':'], $text));
    }

    /** Aiken: question line, then "A) option" lines, then "ANSWER: B". */
    protected function parseAiken(string $contents): array
    {
        $questions = [];
        $errors = [];
        $blocks = preg_split('/\n\s*\n/', trim($contents)) ?: [];

        foreach ($blocks as $index => $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }

            $prompt = null;
            $options = [];
            $correctLetters = [];

            foreach ($lines as $line) {
                if (preg_match('/^ANSWER\s*:\s*([A-Z, ]+)$/i', $line, $m)) {
                    $correctLetters = array_filter(array_map('trim', explode(',', strtoupper($m[1]))));
                } elseif (preg_match('/^([A-Z])[\)\.]\s*(.+)$/', $line, $m)) {
                    $options[strtoupper($m[1])] = trim($m[2]);
                } elseif ($prompt === null) {
                    $prompt = $line;
                } else {
                    $prompt .= ' '.$line;
                }
            }

            if ($prompt === null || $prompt === '') {
                $errors[] = 'Block '.($index + 1).': no question text.';
                continue;
            }
            if ($options === []) {
                $errors[] = 'Block '.($index + 1).': no "A) option" lines found.';
                continue;
            }
            if ($correctLetters === []) {
                $errors[] = 'Block '.($index + 1).': no "ANSWER:" line found.';
                continue;
            }

            $type = count($correctLetters) > 1 ? 'multiple_choice_multiple' : 'multiple_choice_single';
            $share = max(1, count($correctLetters));

            $answers = [];
            foreach ($options as $letter => $text) {
                $isCorrect = in_array($letter, $correctLetters, true);
                $answers[] = [
                    'answer_text' => $text,
                    'fraction' => $isCorrect ? ($type === 'multiple_choice_multiple' ? round(1 / $share, 5) : 1) : 0,
                    'feedback' => null,
                ];
            }

            $questions[] = [
                'name' => null, 'type' => $type, 'prompt' => $prompt,
                'points' => 1, 'category' => null, 'answers' => $answers,
            ];
        }

        return [$questions, $errors];
    }

    /** Plain text: question line, then one or more "Answer:" lines, optional "Case:" and "Marks:". */
    protected function parseShortAnswerText(string $contents): array
    {
        $questions = [];
        $errors = [];
        $blocks = preg_split('/\n\s*\n/', trim($contents)) ?: [];

        foreach ($blocks as $index => $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }

            $prompt = null;
            $accepted = [];
            $caseSensitive = false;
            $points = 1;

            foreach ($lines as $line) {
                if (preg_match('/^Answer\s*:\s*(.+)$/i', $line, $m)) {
                    $accepted[] = trim($m[1]);
                } elseif (preg_match('/^Case\s*:\s*(.+)$/i', $line, $m)) {
                    $caseSensitive = str_starts_with(strtolower(trim($m[1])), 'sens');
                } elseif (preg_match('/^Marks?\s*:\s*([\d.]+)$/i', $line, $m)) {
                    $points = (float) $m[1];
                } elseif ($prompt === null) {
                    $prompt = $line;
                } else {
                    $prompt .= ' '.$line;
                }
            }

            if ($prompt === null || $prompt === '') {
                $errors[] = 'Block '.($index + 1).': no question text.';
                continue;
            }
            if ($accepted === []) {
                $errors[] = 'Block '.($index + 1).': no "Answer:" line found.';
                continue;
            }

            $questions[] = [
                'name' => null,
                'type' => 'short_answer',
                'prompt' => $prompt,
                'points' => $points,
                'category' => null,
                'case_sensitive' => $caseSensitive,
                'answers' => collect($accepted)->map(fn ($text) => [
                    'answer_text' => $text, 'fraction' => 1, 'feedback' => null,
                ])->all(),
            ];
        }

        return [$questions, $errors];
    }

    /** Plain text: question line, then optional "Mark:" and "Answer guide:". */
    protected function parseEssayText(string $contents): array
    {
        $questions = [];
        $errors = [];
        $blocks = preg_split('/\n\s*\n/', trim($contents)) ?: [];

        foreach ($blocks as $index => $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }

            $prompt = null;
            $points = 1;
            $guide = null;

            foreach ($lines as $line) {
                if (preg_match('/^Marks?\s*:\s*([\d.]+)$/i', $line, $m)) {
                    $points = (float) $m[1];
                } elseif (preg_match('/^Answer\s*guide\s*:\s*(.+)$/i', $line, $m)) {
                    $guide = trim($m[1]);
                } elseif ($guide !== null) {
                    $guide .= ' '.$line; // a guide that wrapped onto the next line
                } elseif ($prompt === null) {
                    $prompt = $line;
                } else {
                    $prompt .= ' '.$line;
                }
            }

            if ($prompt === null || $prompt === '') {
                $errors[] = 'Block '.($index + 1).': no question text.';
                continue;
            }

            $questions[] = [
                'name' => null,
                'type' => 'essay',
                'prompt' => $prompt,
                'points' => $points,
                'category' => null,
                'marking_guidance' => $guide,
                'answers' => [],
            ];
        }

        return [$questions, $errors];
    }

    protected function parseJson(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return [[], ['That file is not valid JSON.']];
        }

        $questions = [];
        $errors = [];

        foreach ($decoded as $index => $row) {
            $prompt = trim((string) ($row['question'] ?? ''));
            if ($prompt === '') {
                $errors[] = 'Item '.($index + 1).': no question text.';
                continue;
            }

            $answers = collect($row['answers'] ?? []);
            $type = $row['skulos_type'] ?? $this->skulosType(
                (string) ($row['type'] ?? 'multichoice'), '', $answers->where('fraction', '>', 0)->count()
            );

            $questions[] = [
                'name' => $row['name'] ?? null,
                'type' => in_array($type, CbtQuestion::TYPES, true) ? $type : 'multiple_choice_single',
                'prompt' => $prompt,
                'points' => is_numeric($row['marks'] ?? null) ? (float) $row['marks'] : 1,
                'category' => $row['category'] ?? null,
                'case_sensitive' => (bool) ($row['case_sensitive'] ?? false),
                'general_feedback' => $row['general_feedback'] ?? null,
                'marking_guidance' => $row['marking_guidance'] ?? null,
                'answers' => $answers->map(fn ($a) => [
                    'answer_text' => (string) ($a['text'] ?? ''),
                    'fraction' => (float) ($a['fraction'] ?? 0),
                    'feedback' => $a['feedback'] ?? null,
                ])->values()->all(),
            ];
        }

        return [$questions, $errors];
    }

    /** Moodle's "multichoice" covers both our single and multiple variants; the correct-answer count disambiguates. */
    protected function skulosType(string $moodleType, string $correctRaw = '', ?int $correctCount = null): string
    {
        $count = $correctCount ?? count(array_filter(explode('|', $correctRaw)));

        return match (strtolower($moodleType)) {
            'truefalse' => 'true_false',
            'shortanswer' => 'short_answer',
            'essay' => 'essay',
            'matching' => 'matching',
            'multicheck' => 'multiple_choice_multiple',
            default => $count > 1 ? 'multiple_choice_multiple' : 'multiple_choice_single',
        };
    }

    /** Builds answer rows in the shape CbtQuestion expects for the given type. */
    protected function answersFrom(string $type, array $options, array $correct): array
    {
        if ($type === 'essay') {
            return [];
        }

        if ($type === 'matching') {
            return collect($correct)->map(function (string $pair) {
                [$stem, $match] = array_pad(explode('=', $pair, 2), 2, null);

                return ['answer_text' => trim((string) $stem), 'fraction' => 1, 'feedback' => trim((string) $match)];
            })->filter(fn ($row) => $row['answer_text'] !== '')->values()->all();
        }

        if ($type === 'short_answer') {
            return collect($correct ?: $options)
                ->map(fn (string $text) => ['answer_text' => $text, 'fraction' => 1, 'feedback' => null])
                ->values()->all();
        }

        $share = max(1, count($correct));

        return collect($options)->map(fn (string $text) => [
            'answer_text' => $text,
            'fraction' => in_array($text, $correct, true) ? ($type === 'multiple_choice_multiple' ? round(1 / $share, 5) : 1) : 0,
            'feedback' => null,
        ])->values()->all();
    }
}
