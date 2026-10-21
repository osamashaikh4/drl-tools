"""Activity worker: counts people on the chosen CCTV channels and what they are doing.

Run once a minute by Task Scheduler (task "DRL Activity Worker"):
    pythonw.exe C:\\xampp\\htdocs\\drl-tools\\activity\\worker.py
Each run works for about a minute, then exits. Settings come from data/settings.json (written by
index.php); when the tool is switched off the run exits straight away.

Per camera and cycle: two sub-stream pictures about a second apart, YOLO11n for people and phones,
YOLO11n-pose for the people found. Each person is labelled standing / sitting / walking, plus phone.
Only the counts are stored (data/activity.sqlite) and the last annotated picture per camera
(data/latest/), which is overwritten every cycle. No faces, names or video are kept.

Needs: Python 3 with onnxruntime, opencv-python, numpy; models/yolo11n.onnx and models/yolo11n-pose.onnx.
"""
import json
import msvcrt
import os
import sqlite3
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort

HERE = Path(__file__).resolve().parent
DATA = HERE / 'data'
LATEST = DATA / 'latest'
PHP = r'C:\xampp\php\php.exe'
RUN_FOR = 55          # seconds per run; the next run starts a minute after this one
SIZE = 640
PERSON, PHONE = 0, 67  # COCO classes
PERSON_MIN, PHONE_MIN, POSE_MIN = 0.5, 0.3, 0.45


def log(msg):
    print(time.strftime('%H:%M:%S'), msg, file=sys.stderr, flush=True)


def read_json(path, default):
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return default


def write_json(path, data):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(data), encoding='utf-8')
    os.replace(tmp, path)


# ---- Database ------------------------------------------------------------------------------

def open_db():
    db = sqlite3.connect(DATA / 'activity.sqlite', timeout=10)
    db.execute('PRAGMA journal_mode = WAL')
    # One row per camera per cycle. secs = the interval then, so time spent can be added up.
    db.execute('''CREATE TABLE IF NOT EXISTS samples (
        cam TEXT NOT NULL, ts INTEGER NOT NULL, secs INTEGER NOT NULL, people INTEGER NOT NULL,
        standing INTEGER NOT NULL, sitting INTEGER NOT NULL, walking INTEGER NOT NULL, phone INTEGER NOT NULL)''')
    db.execute('CREATE INDEX IF NOT EXISTS samples_ts ON samples (ts)')
    db.execute('CREATE INDEX IF NOT EXISTS samples_cam_ts ON samples (cam, ts)')
    return db


# ---- Cameras -------------------------------------------------------------------------------

def snapshot(dev, ch):
    """Sub-stream picture of one channel (camera: channel 1; NVR: its channel number)."""
    url = f"http://{dev['ip']}/ISAPI/Streaming/channels/{ch}02/picture"
    mgr = urllib.request.HTTPPasswordMgrWithDefaultRealm()
    mgr.add_password(None, url, dev['user'], dev['password'])
    opener = urllib.request.build_opener(urllib.request.HTTPDigestAuthHandler(mgr), urllib.request.HTTPBasicAuthHandler(mgr))
    data = opener.open(url, timeout=10).read()
    img = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError('The camera did not send a picture')
    return img


# ---- Models --------------------------------------------------------------------------------

class Models:
    def __init__(self, threads):
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = threads
        opts.inter_op_num_threads = 1
        self.det = ort.InferenceSession(str(HERE / 'models' / 'yolo11n.onnx'), opts, providers=['CPUExecutionProvider'])
        self.pose = ort.InferenceSession(str(HERE / 'models' / 'yolo11n-pose.onnx'), opts, providers=['CPUExecutionProvider'])

    @staticmethod
    def letterbox(img):
        h, w = img.shape[:2]
        r = SIZE / max(h, w)
        nh, nw = int(round(h * r)), int(round(w * r))
        canvas = np.full((SIZE, SIZE, 3), 114, np.uint8)
        canvas[:nh, :nw] = cv2.resize(img, (nw, nh))
        return canvas[:, :, ::-1].transpose(2, 0, 1)[None].astype(np.float32) / 255.0, r

    def detect(self, img):
        """People and phones: [(class, score, box x1 y1 x2 y2)]."""
        blob, r = self.letterbox(img)
        out = self.det.run(None, {self.det.get_inputs()[0].name: blob})[0][0].T  # (8400, 84)
        found = []
        for cls, thr in ((PERSON, PERSON_MIN), (PHONE, PHONE_MIN)):
            sc = out[:, 4 + cls]
            keep = sc > thr
            if keep.any():
                boxes = xyxy(out[keep, :4]) / r
                found += [(cls, float(sc[keep][k]), boxes[k]) for k in nms(boxes, sc[keep])]
        return found

    def poses(self, img):
        """People with keypoints: [(box, score, keypoints 17 x (x, y, conf))]."""
        blob, r = self.letterbox(img)
        out = self.pose.run(None, {self.pose.get_inputs()[0].name: blob})[0][0].T  # (8400, 56)
        keep = out[:, 4] > POSE_MIN
        if not keep.any():
            return []
        o = out[keep]
        boxes = xyxy(o[:, :4]) / r
        kps = o[:, 5:].reshape(-1, 17, 3).copy()
        kps[:, :, :2] /= r
        return [(boxes[k], float(o[k, 4]), kps[k]) for k in nms(boxes, o[:, 4])]


def xyxy(b):
    return np.stack([b[:, 0] - b[:, 2] / 2, b[:, 1] - b[:, 3] / 2, b[:, 0] + b[:, 2] / 2, b[:, 1] + b[:, 3] / 2], 1)


def nms(boxes, scores, iou=0.45):
    rects = [[float(x1), float(y1), float(x2 - x1), float(y2 - y1)] for x1, y1, x2, y2 in boxes]
    return list(np.array(cv2.dnn.NMSBoxes(rects, scores.tolist(), 0.0, iou)).flatten())


def iou(a, b):
    x1, y1, x2, y2 = max(a[0], b[0]), max(a[1], b[1]), min(a[2], b[2]), min(a[3], b[3])
    inter = max(0, x2 - x1) * max(0, y2 - y1)
    return inter / ((a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter + 1e-6)


def center(b):
    return np.array([(b[0] + b[2]) / 2, (b[1] + b[3]) / 2])


# COCO keypoints: 11/12 hips, 13/14 knees.
def posture(kp, box):
    """Sitting when the thighs are nearer horizontal than vertical; box shape when the legs are hidden."""
    angles = []
    if kp is not None:
        for hip, knee in ((11, 13), (12, 14)):
            if kp[hip, 2] > 0.5 and kp[knee, 2] > 0.5:
                dx, dy = kp[knee, 0] - kp[hip, 0], kp[knee, 1] - kp[hip, 1]
                angles.append(np.degrees(np.arctan2(abs(dx), max(dy, 1e-3))))
    if angles:
        return 'sitting' if np.mean(angles) > 50 else 'standing'
    w, h = box[2] - box[0], box[3] - box[1]
    return 'sitting' if h < 1.6 * w else 'standing'


def analyse(models, before, img):
    """People in img with a label each; 'before' is the person boxes about a second earlier."""
    found = models.detect(img)
    phones = [b for c, s, b in found if c == PHONE]
    if not any(c == PERSON for c, s, b in found):
        return [], phones
    people = [(b, s, k) for b, s, k in models.poses(img)] or [(b, s, None) for c, s, b in found if c == PERSON]
    result = []
    for box, score, kp in people:
        label = posture(kp, box)
        # Walking: the same person (best box overlap) moved more than 15 % of their height.
        if label == 'standing' and before:
            prev = max(before, key=lambda p: iou(p, box))
            if iou(prev, box) > 0.1 and np.linalg.norm(center(prev) - center(box)) / max(box[3] - box[1], 1) > 0.15:
                label = 'walking'
        phone = any(box[0] <= center(p)[0] <= box[2] and box[1] <= center(p)[1] <= box[3] for p in phones)
        result.append({'box': box, 'score': score, 'kp': kp, 'label': label, 'phone': phone})
    return result, phones


SKELETON = [(5, 6), (5, 7), (7, 9), (6, 8), (8, 10), (5, 11), (6, 12), (11, 12), (11, 13), (13, 15), (12, 14), (14, 16)]
COLORS = {'standing': (0, 170, 0), 'sitting': (200, 120, 0), 'walking': (0, 140, 255)}


def draw(img, people, phones):
    for p in people:
        x1, y1, x2, y2 = map(int, p['box'])
        c = COLORS[p['label']]
        cv2.rectangle(img, (x1, y1), (x2, y2), c, 2)
        text = p['label'] + (' + phone' if p['phone'] else '')
        cv2.putText(img, text, (x1, max(14, y1 - 5)), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 3)
        cv2.putText(img, text, (x1, max(14, y1 - 5)), cv2.FONT_HERSHEY_SIMPLEX, 0.5, c, 1)
        if p['kp'] is not None:
            kp = p['kp']
            for a, b in SKELETON:
                if kp[a, 2] > 0.5 and kp[b, 2] > 0.5:
                    cv2.line(img, tuple(map(int, kp[a, :2])), tuple(map(int, kp[b, :2])), c, 1)
    for pb in phones:
        x1, y1, x2, y2 = map(int, pb)
        cv2.rectangle(img, (x1, y1), (x2, y2), (0, 0, 255), 2)
    return img


# ---- One cycle over every chosen camera -----------------------------------------------------

def cycle(models, db, settings, devices, status):
    start = time.time()
    ts = int(start)
    cams = []
    for c in settings.get('cameras', []):
        dev = devices.get(c.get('ip'))
        key = f"{c.get('ip')}-{int(c.get('ch', 1))}"
        if dev:
            cams.append((key, {**dev, 'ip': c['ip']}, int(c.get('ch', 1))))
        else:
            status['cams'][key] = {'ts': ts, 'ok': False, 'error': 'Not in the CCTV tool any more'}

    # First pass: where the people are now, to see who moves before the second pass.
    first = {}
    for key, dev, ch in cams:
        try:
            first[key] = (time.time(), [b for c, s, b in models.detect(snapshot(dev, ch)) if c == PERSON])
        except Exception as e:  # noqa: BLE001 - one camera failing must not stop the others
            first[key] = (time.time(), None)
            status['cams'][key] = {'ts': ts, 'ok': False, 'error': str(e)[:200]}

    rows = []
    for key, dev, ch in cams:
        taken, before = first[key]
        if before is None:
            continue
        time.sleep(max(0.0, 1.0 - (time.time() - taken)))
        try:
            img = snapshot(dev, ch)
            people, phones = analyse(models, before, img)
        except Exception as e:  # noqa: BLE001
            status['cams'][key] = {'ts': ts, 'ok': False, 'error': str(e)[:200]}
            continue
        n = {k: sum(p['label'] == k for p in people) for k in ('standing', 'sitting', 'walking')}
        n['phone'] = sum(p['phone'] for p in people)
        rows.append((key, ts, int(settings['interval']), len(people), n['standing'], n['sitting'], n['walking'], n['phone']))
        LATEST.mkdir(exist_ok=True)
        ok, jpg = cv2.imencode('.jpg', draw(img, people, phones), [cv2.IMWRITE_JPEG_QUALITY, 80])
        if ok:
            tmp = LATEST / f'{key}.tmp'
            tmp.write_bytes(jpg.tobytes())
            os.replace(tmp, LATEST / f'{key}.jpg')
        status['cams'][key] = {'ts': ts, 'ok': True, 'people': len(people), **n}

    with db:
        db.executemany('INSERT INTO samples VALUES (?, ?, ?, ?, ?, ?, ?, ?)', rows)
    status['lastCycle'] = ts
    status['cycleSeconds'] = round(time.time() - start, 1)
    status['cycleCams'] = len(cams)


def main():
    DATA.mkdir(exist_ok=True)
    # One worker at a time: a run that overlaps the previous one just exits.
    lock = open(DATA / 'worker.lock', 'a+')
    try:
        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        return

    started = time.time()
    status_file = DATA / 'status.json'
    status = read_json(status_file, {})
    status.setdefault('cams', {})
    status['heartbeat'] = int(started)
    status.pop('error', None)
    settings = read_json(DATA / 'settings.json', {})
    if not settings.get('enabled') or not settings.get('cameras'):
        write_json(status_file, status)
        return

    try:
        devices = json.loads(subprocess.check_output([PHP, str(HERE / 'devices.php')], timeout=30))
        models = Models(int(settings.get('threads', 2)))
        db = open_db()
    except Exception as e:  # noqa: BLE001 - shown on the page
        status['error'] = str(e)[:300]
        write_json(status_file, status)
        log(status['error'])
        return

    while True:
        settings = read_json(DATA / 'settings.json', settings)
        if not settings.get('enabled'):
            break
        interval = max(15, int(settings.get('interval', 60)))
        settings['interval'] = interval
        wait = status.get('lastCycle', 0) + interval - time.time()
        if time.time() + max(wait, 0) > started + RUN_FOR:
            break
        if wait > 0:
            time.sleep(wait)
        # Drop cameras no longer chosen from the status shown on the page.
        chosen = {f"{c.get('ip')}-{int(c.get('ch', 1))}" for c in settings.get('cameras', [])}
        status['cams'] = {k: v for k, v in status['cams'].items() if k in chosen}
        try:
            cycle(models, db, settings, devices, status)
        except Exception as e:  # noqa: BLE001
            status['error'] = str(e)[:300]
            log(status['error'])
        status['heartbeat'] = int(time.time())
        write_json(status_file, status)

    # Old counts are trimmed once an hour.
    if time.localtime().tm_min == 0:
        keep = max(1, int(settings.get('keepDays', 90)))
        with db:
            db.execute('DELETE FROM samples WHERE ts < ?', (int(time.time()) - keep * 86400,))
    status['heartbeat'] = int(time.time())
    write_json(status_file, status)


if __name__ == '__main__':
    main()
