import { useEffect, useMemo, useRef, useState } from 'react';
import { Camera, Upload, X } from 'lucide-react';
import CameraDialog from './CameraDialog';
import './PhotoInput.css';

// Phones and tablets: their own camera app (through <input capture>) takes far better photos
// than a browser video stream, and it's what people expect there.
const isTouchDevice = () => window.matchMedia?.('(pointer: coarse)').matches ?? false;
// getUserMedia only exists on secure pages (https, or localhost in development).
const canUseWebcam = () => Boolean(navigator.mediaDevices?.getUserMedia);

// The real <input> always holds the same files as the selection — camera shots included — so
// the form's own `required` check passes or fails on what the user actually sees.
function syncInput(input, selection) {
  if (!input) return;
  try {
    const transfer = new DataTransfer();
    selection.forEach((f) => transfer.items.add(f));
    input.files = transfer.files;
  } catch {
    // Browsers without a DataTransfer constructor: the forms' own checks still catch a missing
    // photo before sending.
  }
}

function formatSize(bytes) {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * A photo field that can take a picture as well as upload one. "Take photo" opens the phone's
 * camera on a phone and an in-app webcam window (CameraDialog) on a laptop; "Upload" picks an
 * existing image as before. Either way the result is an ordinary image File, so the forms send
 * it exactly as they did an uploaded one.
 *
 * Controlled: `files` is the current selection (an array, a FileList or null) and `onChange`
 * receives the new array. With `multiple`, photos accumulate (take one, upload two more, …); a
 * single field replaces its photo. Each chosen photo is shown with a ✕ to remove it — as a
 * thumbnail, or as a name chip when the page draws its own larger preview (`thumbnails={false}`).
 */
export default function PhotoInput({
  files,
  onChange,
  multiple = false,
  required = false,
  disabled = false,
  thumbnails = true,
  facingMode = 'environment',
  label = multiple ? 'Photos' : 'Photo',
  cameraTitle,
}) {
  const uploadRef = useRef(null);
  const captureRef = useRef(null);
  const [cameraOpen, setCameraOpen] = useState(false);
  // The selection as an array that only changes when the files do. Callers often pass a fresh
  // `[file]` each render; without this, every keystroke elsewhere in the form would rebuild the
  // thumbnails. (Adjusting state while rendering is React's documented pattern for this.)
  const [list, setList] = useState(() => (files ? Array.from(files) : []));
  const incoming = files ? Array.from(files) : [];
  if (incoming.length !== list.length || incoming.some((f, i) => f !== list[i])) {
    setList(incoming);
  }

  useEffect(() => { syncInput(uploadRef.current, list); }, [list]);

  const previews = useMemo(
    () => (thumbnails ? list.map((file) => ({ file, url: URL.createObjectURL(file) })) : []),
    [list, thumbnails],
  );
  useEffect(() => () => previews.forEach((p) => URL.revokeObjectURL(p.url)), [previews]);

  const add = (incoming) => {
    const images = incoming.filter((f) => f.type.startsWith('image/'));
    if (images.length === 0) {
      syncInput(uploadRef.current, list); // a cancelled picker empties the input; put the selection back
      return;
    }
    onChange(multiple ? [...list, ...images] : [images[0]]);
  };

  const fromPicker = (e) => {
    add(Array.from(e.target.files || []));
  };

  const fromPhoneCamera = (e) => {
    add(Array.from(e.target.files || []));
    e.target.value = ''; // so the next shot fires a change even if the name repeats
  };

  const remove = (index) => onChange(list.filter((_, i) => i !== index));

  const takePhoto = () => {
    if (isTouchDevice() || !canUseWebcam()) captureRef.current?.click();
    else setCameraOpen(true);
  };

  const keyFor = (f, i) => `${f.name}-${f.size}-${f.lastModified}-${i}`;

  return (
    <div className="photo-input">
      <div className="photo-input-actions">
        <button type="button" className="photo-input-btn photo-input-btn-primary" onClick={takePhoto} disabled={disabled}>
          <Camera size={16} aria-hidden="true" /> {!multiple && list.length > 0 ? 'Retake photo' : 'Take photo'}
        </button>
        <button type="button" className="photo-input-btn" onClick={() => uploadRef.current?.click()} disabled={disabled}>
          <Upload size={16} aria-hidden="true" /> {multiple ? 'Upload photos' : 'Upload a photo'}
        </button>
      </div>

      {/* Upload picker. Drawn as nothing but not display:none, so the browser can still point
          at it when a required photo is missing. Reached through the buttons above. */}
      <input
        ref={uploadRef}
        className="photo-input-file"
        type="file"
        accept="image/*"
        multiple={multiple}
        required={required}
        disabled={disabled}
        tabIndex={-1}
        aria-label={label}
        onChange={fromPicker}
      />
      {/* Phone camera: `capture` opens the camera app directly instead of the gallery. */}
      <input ref={captureRef} type="file" accept="image/*" capture={facingMode} hidden onChange={fromPhoneCamera} />

      {list.length > 0 && (thumbnails ? (
        <>
          <ul className="photo-input-thumbs" aria-label={`Chosen ${label.toLowerCase()}`}>
            {previews.map((p, i) => (
              <li key={keyFor(p.file, i)}>
                <img src={p.url} alt={`Chosen photo ${i + 1}`} />
                <button type="button" className="photo-input-remove" onClick={() => remove(i)} disabled={disabled} aria-label={`Remove photo ${i + 1}`}>
                  <X size={13} aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
          {multiple && <p className="photo-input-count">{list.length} photo{list.length === 1 ? '' : 's'} ready</p>}
        </>
      ) : (
        <ul className="photo-input-chips" aria-label={`Chosen ${label.toLowerCase()}`}>
          {list.map((f, i) => (
            <li key={keyFor(f, i)}>
              <span className="name">{f.name} · {formatSize(f.size)}</span>
              <button type="button" className="photo-input-chip-remove" onClick={() => remove(i)} disabled={disabled}>Remove</button>
            </li>
          ))}
        </ul>
      ))}

      {cameraOpen && (
        <CameraDialog
          multiple={multiple}
          facingMode={facingMode}
          title={cameraTitle}
          onCapture={(file) => add([file])}
          onClose={() => setCameraOpen(false)}
          onUploadInstead={() => uploadRef.current?.click()}
        />
      )}
    </div>
  );
}
