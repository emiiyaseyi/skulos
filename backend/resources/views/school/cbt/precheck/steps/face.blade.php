@php($faceApproval = $faceApproval ?? null)

@if($faceApproval && $faceApproval->status === 'pending')
  <h2 style="margin-bottom:.6rem">Waiting for approval</h2>
  <p class="muted" style="margin-bottom:1rem">Your face capture did not automatically match your photo ID closely enough. A member of staff needs to review it before you can continue. This page will move on by itself once approved &mdash; check back, or refresh.</p>
  <a class="btn btn-primary btn-block" href="{{ route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]) }}">Check status</a>
@elseif($faceApproval && $faceApproval->status === 'rejected')
  <h2 style="margin-bottom:.6rem">Identity check rejected</h2>
  <p class="muted" style="margin-bottom:1rem">Your identity request was not approved{{ $faceApproval->notes ? ' ('.$faceApproval->notes.')' : '' }}. Please contact a member of staff.</p>
@else
  <h2 style="margin-bottom:.6rem">Face verification</h2>
  <p class="muted" style="margin-bottom:.8rem">Look directly at your camera. We will match your live face to the photo ID you just uploaded.</p>
  <video id="facePreview" autoplay playsinline muted style="width:100%;border-radius:8px;background:#000;margin-bottom:.6rem"></video>
  <div style="height:6px;background:var(--surface-2,#eee);border-radius:999px;overflow:hidden;margin-bottom:.4rem">
    <div id="matchBar" style="height:100%;width:0%;background:var(--bad,#dc3545)"></div>
  </div>
  <p id="faceStatus" class="muted" style="margin-bottom:1rem">Loading face verification...</p>

  <input type="hidden" name="face_capture" id="faceCapture" value="" />
  <input type="hidden" name="match_score" id="matchScore" value="" />
  <button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>
  <button class="btn btn-ghost btn-block" type="submit" id="requestReviewBtn" formaction="{{ route('school.cbt.precheck.face-review', ['school' => $school->slug, 'precheckId' => $precheck->id]) }}" style="display:none;margin-top:.5rem">Request invigilator approval</button>

  @push('scripts')
  <script src="{{ asset('vendor/proctoring/face-api.min.js') }}"></script>
  <script>
  (function () {
    var status = document.getElementById('faceStatus');
    var matchBar = document.getElementById('matchBar');
    var video = document.getElementById('facePreview');
    var captureField = document.getElementById('faceCapture');
    var scoreField = document.getElementById('matchScore');
    var continueBtn = document.getElementById('precheckContinue');
    var reviewBtn = document.getElementById('requestReviewBtn');
    var photoIdUrl = '{{ route('school.cbt.precheck.photo-id-image', ['school' => $school->slug, 'precheckId' => $precheck->id]) }}';
    var modelUrl = '{{ asset('vendor/proctoring/models') }}';
    var attempts = 0;
    var maxAttempts = 3;
    var matchInterval = null;

    if (typeof faceapi === 'undefined') {
      status.textContent = 'Face verification could not load. Please contact a member of staff.';
      return;
    }

    Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
      faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl),
    ]).then(function () {
      status.textContent = 'Loading your photo ID...';
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () {
        faceapi.detectSingleFace(img, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
          .then(function (idDet) {
            if (!idDet) {
              status.textContent = 'Could not detect a face in your photo ID. Please go back and re-upload a clearer photo.';
              return;
            }
            startCamera(idDet.descriptor);
          });
      };
      img.onerror = function () {
        status.textContent = 'Could not load your photo ID.';
      };
      img.src = photoIdUrl;
    }).catch(function () {
      status.textContent = 'Face verification could not load. Please contact a member of staff.';
    });

    function startCamera(idDescriptor) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        status.textContent = 'Your browser does not support camera access.';
        return;
      }
      navigator.mediaDevices.getUserMedia({ video: true }).then(function (stream) {
        video.srcObject = stream;
        status.textContent = 'Matching your face to your photo ID...';
        matchInterval = setInterval(function () { attemptMatch(idDescriptor); }, 2500);
      }).catch(function () {
        status.textContent = 'Camera access was blocked. Please allow camera access and reload this page.';
      });
    }

    function attemptMatch(idDescriptor) {
      if (video.readyState < 2) return;
      faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
        .then(function (det) {
          if (!det) {
            status.textContent = 'No face detected — move into camera frame.';
            return;
          }
          var distance = faceapi.euclideanDistance(idDescriptor, det.descriptor);
          var pct = Math.max(0, Math.min(100, Math.round((1 - distance) * 100)));
          matchBar.style.width = pct + '%';
          matchBar.style.background = pct >= 50 ? 'var(--ok, #28a745)' : 'var(--bad, #dc3545)';

          var canvas = document.createElement('canvas');
          canvas.width = video.videoWidth || 480;
          canvas.height = video.videoHeight || 360;
          canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
          captureField.value = canvas.toDataURL('image/jpeg', 0.85);
          scoreField.value = (pct / 100).toFixed(4);

          if (pct >= 50) {
            clearInterval(matchInterval);
            status.textContent = 'Identity verified, ' + pct + '% match.';
            continueBtn.disabled = false;
            try { sessionStorage.setItem('cbt_face_descriptor', JSON.stringify(Array.from(det.descriptor))); } catch (e) {}
            return;
          }

          attempts++;
          var remaining = maxAttempts - attempts;
          status.textContent = pct + '% match — need 50%+. ' + (remaining > 0 ? remaining + ' attempt(s) remaining.' : '');
          if (attempts >= maxAttempts) {
            clearInterval(matchInterval);
            status.textContent = 'Could not reach 50% after 3 attempts. Request invigilator approval below.';
            reviewBtn.style.display = 'block';
          }
        }).catch(function () {});
    }
  })();
  </script>
  @endpush
@endif
