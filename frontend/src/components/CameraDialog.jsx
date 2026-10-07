import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Camera, RefreshCw, SwitchCamera, X } from 'lucide-react';
import './PhotoInput.css';

// Longest side of a captured photo. Plenty for an animal profile or a legible ID, and keeps a
// JPEG well under the 5 MB the API accepts.
const MAX_SIDE = 1920;

function cameraError(err) {
  switch (err?.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return 'Camera access was blocked. Allow the camera for this site in your browser settings, or upload a photo instead.';
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'No camera was found on this device. Upload a photo instead.';
    case 'NotReadableError':
      return 'The camera is being used by another app. Close that app and try again, or upload a photo instead.';
    default:
      return 'The camera could not be started. Upload a photo instead.';
  }
}

const stamp = () => new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');

/**
 * In-app camera for devices without a native camera picker (laptops and desktops with a webcam):
 * live preview → capture → check the shot → use it or retake. Phones skip this and open their own
 * camera app through PhotoInput, which takes better pictures than a browser video stream.
 *
 * `onCapture(file)` receives each photo as a JPEG File, exactly like a picked file. With
 * `multiple`, "Use & take another" keeps the camera open for the next shot.
 */
export default function CameraDialog({ onCapture, onClose, onUploadInstead, multiple = false, facingMode = 'environment', title = 'Take a photo' }) {
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const captureBtnRef = useRef(null);
  const restoreFocusRef = useRef(null);
  const [facing, setFacing] = useState(facingMode);
  const [status, setStatus] = useState('starting'); // starting | live | preview | error
  const [error, setError] = useState('');
  const [shot, setShot] = useState(null); // { blob, url }
  const [canSwitch, setCanSwitch] = useState(false);
  const [taken, setTaken] = useState(0);

  // Start (or restart, when switching cameras) the stream; always release it on the way out so
  // the camera light goes off as soon as the dialog closes.
  useEffect(() => {
    let cancelled = false;
    navigator.mediaDevices
      .getUserMedia({ audio: false, video: { facingMode: { ideal: facing }, width: { ideal: MAX_SIDE }, height: { ideal: 1080 } } })
      .then((stream) => {
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop());
          return;
        }
        streamRef.current = stream;
        if (videoRef.current) videoRef.current.srcObject = stream;
        setStatus('live');
        navigator.mediaDevices.enumerateDevices()
          .then((devices) => { if (!cancelled) setCanSwitch(devices.filter((d) => d.kind === 'videoinput').length > 1); })
          .catch(() => {});
      })
      .catch((err) => {
        if (cancelled) return;
        setError(cameraError(err));
        setStatus('error');
      });

    return () => {
      cancelled = true;
      streamRef.current?.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    };
  }, [facing]);

  // The latest onClose, so the modal setup below runs once rather than on every parent render
  // (callers pass an inline function).
  const onCloseRef = useRef(onClose);
  useEffect(() => { onCloseRef.current = onClose; });

  // Modal behaviour: Escape closes, the page behind doesn't scroll, focus returns afterwards.
  useEffect(() => {
    restoreFocusRef.current = document.activeElement;
    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onCloseRef.current();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
      const target = restoreFocusRef.current;
      if (target?.isConnected) target.focus?.();
    };
  }, []);

  useEffect(() => {
    if (status === 'live') captureBtnRef.current?.focus();
  }, [status]);

  // The preview URL belongs to the current shot; release it when the shot changes or on close.
  useEffect(() => () => { if (shot) URL.revokeObjectURL(shot.url); }, [shot]);

  const capture = () => {
    const video = videoRef.current;
    if (!video?.videoWidth) return;
    const scale = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    canvas.toBlob((blob) => {
      if (!blob) {
        setError('The photo could not be saved. Please try again.');
        setStatus('error');
        return;
      }
      setShot({ blob, url: URL.createObjectURL(blob) });
      setStatus('preview');
    }, 'image/jpeg', 0.9);
  };

  const use = (takeAnother) => {
    onCapture(new File([shot.blob], `photo-${stamp()}-${taken + 1}.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
    setShot(null);
    if (takeAnother) {
      setTaken((n) => n + 1);
      setStatus('live');
    } else {
      onClose();
    }
  };

  const retake = () => {
    setShot(null);
    setStatus('live');
  };

  const switchCamera = () => {
    setStatus('starting');
    setFacing((f) => (f === 'environment' ? 'user' : 'environment'));
  };

  return createPortal(
    <div className="cam-overlay" onClick={onClose}>
      <div className="cam-panel" role="dialog" aria-modal="true" aria-labelledby="cam-title" onClick={(e) => e.stopPropagation()}>
        <div className="cam-head">
          <h2 id="cam-title"><Camera size={18} aria-hidden="true" /> {title}</h2>
          <button type="button" className="cam-icon-btn" onClick={onClose} aria-label="Close camera"><X size={18} /></button>
        </div>

        <div className="cam-stage">
          {/* Kept mounted (just hidden) while previewing, so Retake is instant. */}
          <video ref={videoRef} autoPlay playsInline muted hidden={status !== 'live'} aria-label="Live camera preview" />
          {status === 'preview' && shot && <img src={shot.url} alt="The photo you just took" />}
          {status === 'starting' && <p className="cam-message" role="status">Starting the camera… allow camera access if your browser asks.</p>}
          {status === 'error' && <p className="cam-message cam-error" role="alert">{error}</p>}
        </div>

        {taken > 0 && status !== 'error' && (
          <p className="cam-count" role="status">{taken} photo{taken === 1 ? '' : 's'} added so far.</p>
        )}

        <div className={`cam-actions${status === 'live' ? ' is-live' : ''}`}>
          {status === 'live' && (
            <>
              {canSwitch ? (
                <button type="button" className="cam-btn" onClick={switchCamera}><SwitchCamera size={16} aria-hidden="true" /> Switch camera</button>
              ) : <span />}
              <button type="button" ref={captureBtnRef} className="cam-shutter" onClick={capture} aria-label="Take the photo">
                <span aria-hidden="true" />
              </button>
              <button type="button" className="cam-btn" onClick={onClose}>{taken > 0 ? 'Done' : 'Cancel'}</button>
            </>
          )}
          {status === 'preview' && (
            <>
              <button type="button" className="cam-btn" onClick={retake}><RefreshCw size={16} aria-hidden="true" /> Retake</button>
              {multiple && <button type="button" className="cam-btn" onClick={() => use(true)}>Use &amp; take another</button>}
              <button type="button" className="cam-btn cam-btn-primary" onClick={() => use(false)}>Use photo</button>
            </>
          )}
          {status === 'error' && (
            <>
              <button type="button" className="cam-btn" onClick={onClose}>Close</button>
              {onUploadInstead && (
                <button type="button" className="cam-btn cam-btn-primary" onClick={() => { onClose(); onUploadInstead(); }}>Upload a photo instead</button>
              )}
            </>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
