{{-- Shared SMTP fields for both the platform and per-school mail settings screens. --}}
<div class="grid cols-2">
  <div class="field">
    <label for="host">SMTP host</label>
    <input id="host" name="host" value="{{ old('host', $settings['host'] ?? '') }}" placeholder="smtp.example.com" />
  </div>
  <div class="field">
    <label for="port">Port</label>
    <input id="port" name="port" type="number" value="{{ old('port', $settings['port'] ?? 587) }}" />
  </div>
</div>
<div class="grid cols-2">
  <div class="field">
    <label for="username">Username</label>
    <input id="username" name="username" value="{{ old('username', $settings['username'] ?? '') }}" />
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" placeholder="{{ !empty($settings['password']) ? 'Unchanged' : '' }}" />
    <div class="hint">Leave blank to keep the current password.</div>
  </div>
</div>
<div class="grid cols-2">
  <div class="field">
    <label for="encryption">Encryption</label>
    <select id="encryption" name="encryption">
      <option value="tls" @selected(($settings['encryption'] ?? 'tls') === 'tls')>TLS</option>
      <option value="ssl" @selected(($settings['encryption'] ?? '') === 'ssl')>SSL</option>
      <option value="" @selected(($settings['encryption'] ?? '') === '')>None</option>
    </select>
  </div>
  <div class="field">
    <label for="from_address">From address</label>
    <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $settings['from_address'] ?? '') }}" />
  </div>
</div>
<div class="field">
  <label for="from_name">From name</label>
  <input id="from_name" name="from_name" value="{{ old('from_name', $settings['from_name'] ?? '') }}" />
</div>
