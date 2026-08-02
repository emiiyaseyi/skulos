/**
 * k6 load test: N applicants signing in and taking an admission
 * assessment concurrently, against a REAL staging deployment.
 *
 * Run this against staging (PHP-FPM/Nginx + real MySQL), never against
 * `php artisan serve` — that dev server was tested live during this
 * build and could not survive even 20 concurrent connections, so any
 * number it "handles" would be meaningless. This script is only useful
 * pointed at infrastructure that resembles what a real cPanel/shared
 * hosting deployment actually runs.
 *
 * Install: https://k6.io/docs/get-started/installation/
 *
 * Usage:
 *   BASE_URL=https://greenfield.yourdomain.com \
 *   SCHOOL_SLUG=greenfield \
 *   ASSESSMENT_ID=1 \
 *   APPLICANT_PREFIX=APP/ \
 *   APPLICANT_COUNT=100 \
 *   APPLICANT_START=1 \
 *   APPLICANT_PASSWORD=changeme \
 *   k6 run --vus 100 --duration 5m admission-assessment-load-test.js
 *
 * Every applicant in the range [APPLICANT_START, APPLICANT_START +
 * APPLICANT_COUNT) is assumed to sign in with reference number
 * "{APPLICANT_PREFIX}{n padded to 4 digits}" and password
 * APPLICANT_PASSWORD (a pending_fee applicant's own reference number
 * doubles as their bootstrap password — see Applicant::canSignIn()'s
 * own docblock — so seeding 100 real pending_fee test applicants and
 * using their own admission numbers as APPLICANT_PASSWORD's value is
 * the fastest way to get 100 real, distinct logins without hand-issuing
 * 100 passwords first).
 *
 * Each virtual user: loads the login page (for a fresh CSRF token),
 * signs in, loads the assessment list, starts (or resumes) the
 * assessment, answers a handful of questions with realistic think-time
 * between them, then submits. Metrics are broken out per step so a
 * slowdown shows up as "which step", not just "something is slow".
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://greenfield.localhost:8000';
const SCHOOL_SLUG = __ENV.SCHOOL_SLUG || 'greenfield';
const ASSESSMENT_ID = __ENV.ASSESSMENT_ID || '1';
const APPLICANT_PREFIX = __ENV.APPLICANT_PREFIX || 'APP/';
const APPLICANT_COUNT = parseInt(__ENV.APPLICANT_COUNT || '100', 10);
const APPLICANT_START = parseInt(__ENV.APPLICANT_START || '1', 10);
const APPLICANT_PASSWORD_OVERRIDE = __ENV.APPLICANT_PASSWORD || null;
const ANSWERS_PER_VU = parseInt(__ENV.ANSWERS_PER_VU || '5', 10);

const loginDuration = new Trend('login_duration', true);
const startDuration = new Trend('assessment_start_duration', true);
const answerDuration = new Trend('save_answer_duration', true);
const submitDuration = new Trend('submit_duration', true);
const loginFailRate = new Rate('login_failures');
const startFailRate = new Rate('start_failures');
const answerFailRate = new Rate('save_answer_failures');

export const options = {
  scenarios: {
    concurrent_exam_takers: {
      executor: 'per-vu-iterations',
      vus: APPLICANT_COUNT,
      iterations: 1,
      maxDuration: '15m',
    },
  },
  thresholds: {
    login_failures: ['rate<0.02'],
    start_failures: ['rate<0.02'],
    save_answer_failures: ['rate<0.05'],
    login_duration: ['p(95)<3000'],
    assessment_start_duration: ['p(95)<5000'],
    save_answer_duration: ['p(95)<2000'],
  },
};

function referenceNumberFor(vuIndex) {
  const n = APPLICANT_START + vuIndex;

  return APPLICANT_PREFIX + String(n).padStart(4, '0');
}

function extractCsrfToken(html) {
  const match = html.match(/name="csrf-token" content="([^"]+)"/) || html.match(/name="_token" value="([^"]+)"/);

  return match ? match[1] : null;
}

export default function () {
  const jar = http.cookieJar();
  const identifier = referenceNumberFor(__VU - 1);
  const password = APPLICANT_PASSWORD_OVERRIDE || identifier;

  // 1. Load the login page for a real CSRF token + session cookie.
  const loginPageRes = http.get(`${BASE_URL}/admissions/login`, { jar });
  const csrfToken = extractCsrfToken(loginPageRes.body);
  check(loginPageRes, { 'login page loaded': (r) => r.status === 200 && csrfToken !== null });

  // 2. Sign in.
  const loginStart = Date.now();
  const loginRes = http.post(
    `${BASE_URL}/admissions/login`,
    { _token: csrfToken, identifier, password },
    { jar, redirects: 5 }
  );
  loginDuration.add(Date.now() - loginStart);
  const loginOk = loginRes.status === 200 && loginRes.url.includes('/admissions/dashboard');
  loginFailRate.add(!loginOk);
  if (!loginOk) {
    console.error(`VU ${__VU} (${identifier}): login failed, status=${loginRes.status} url=${loginRes.url}`);

    return;
  }

  sleep(Math.random() * 2);

  // 3. Start (or resume) the assessment.
  const startPageRes = http.get(`${BASE_URL}/admissions/assessment`, { jar });
  const startToken = extractCsrfToken(startPageRes.body);

  const startBegin = Date.now();
  const startRes = http.post(
    `${BASE_URL}/admissions/assessment/${ASSESSMENT_ID}/start`,
    { _token: startToken },
    { jar, redirects: 5 }
  );
  startDuration.add(Date.now() - startBegin);
  const startOk = startRes.status === 200 && /\/admissions\/assessment\/session\/\d+/.test(startRes.url);
  startFailRate.add(!startOk);
  if (!startOk) {
    console.error(`VU ${__VU} (${identifier}): could not start assessment, status=${startRes.status} url=${startRes.url}`);

    return;
  }

  const sessionMatch = startRes.url.match(/\/session\/(\d+)/);
  const sessionId = sessionMatch ? sessionMatch[1] : null;
  const sessionCsrf = extractCsrfToken(startRes.body);

  // 4. Answer a handful of questions with realistic think-time between
  //    each, mirroring how a real candidate actually paces an exam
  //    rather than firing every request back to back.
  for (let i = 0; i < ANSWERS_PER_VU; i++) {
    sleep(2 + Math.random() * 8);

    const answerBegin = Date.now();
    const answerRes = http.post(
      `${BASE_URL}/admissions/assessment/session/${sessionId}/answer`,
      JSON.stringify({ question_id: i + 1, answer: '1' }),
      {
        jar,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': sessionCsrf,
        },
      }
    );
    answerDuration.add(Date.now() - answerBegin);
    answerFailRate.add(answerRes.status !== 200);
  }

  // 5. Submit.
  const submitBegin = Date.now();
  const submitRes = http.post(
    `${BASE_URL}/admissions/assessment/session/${sessionId}/submit`,
    { _token: sessionCsrf },
    { jar, redirects: 5 }
  );
  submitDuration.add(Date.now() - submitBegin);
  check(submitRes, { 'submitted successfully': (r) => r.status === 200 });
}
