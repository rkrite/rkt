#!/usr/bin/env python3
"""
vxd.py — IRB Race GPX Analyser & Splitter
==========================================
Parses VBOX GPX files from IRB (Inflatable Rescue Boat) racing sessions.

Race detection:
    A new race is identified whenever there is a gap of >= 15 seconds where
    the GPS speed stays <= IDLE_SPEED_KMH (i.e. the boat is stationary or
    drifting between runs).

Shape validation (IRB race pattern):
    A genuine IRB race has a very specific, direction-agnostic shape:
      1. TWO genuine ~180° reversals (one near-shore buoy, one far buoy).
         Both turn-score peaks must exceed MIN_TURN_ANGLE degrees.
      2. The boat RETURNS to its start — the straight-line distance from the
         last point to the first point is <= LOOP_CLOSURE_PCT % of the total
         GPS distance travelled. This distinguishes a complete race loop from
         a partial lap or random milling.

    Because the shape criterion is purely based on heading-change magnitude and
    spatial closure — not on absolute compass direction or distance — it works
    correctly regardless of which way the beach faces or how long the course is.

Per-race metrics:
    • Max speed     (km/h)
    • Race distance (metres, total GPS path length)
    • Turn 1 speed  (km/h) — first large heading reversal (chronological)
    • Turn 2 speed  (km/h) — second large heading reversal (chronological)

Outputs:
    • ./output/race_<N>_<timestamp>.gpx  — one GPX per segment
    • ./output/summary.csv               — all metrics in one table (incl. shape flag)

Usage:
    # Analyse and export everything (including non-races):
    python vxd.py _devdocs/session.gpx

    # Only export confirmed IRB race tracks:
    python vxd.py _devdocs/session.gpx --real-races-only

    # Multiple files, custom output directory:
    python vxd.py _devdocs/*.gpx -o ./output --real-races-only
"""

import argparse
import csv
import math
import os
import sys
from datetime import datetime
import xml.etree.ElementTree as ET

# ── tuneable constants ──────────────────────────────────────────────────────
IDLE_SPEED_KMH    = 5     # km/h  — below this the boat is "stopped"
GAP_MIN_SECONDS   = 5     # seconds of idling that signals a new race.  7 s is
                          # enough to catch the brief slowdown between back-to-back
                          # heats (drivers can restart within ~8-15 s) while
                          # staying well above any momentary speed dip that
                          # occurs inside a single race leg.
MIN_RACE_SPEED    = 15    # km/h  — candidate must reach at least this speed
MIN_RACE_DURATION = 10    # seconds — discard very short stubs

# IRB shape validation thresholds
MIN_TURN_ANGLE    = 160   # degrees — both turns must reverse at least this much
LOOP_CLOSURE_PCT  = 10.0  # % — end point must be within this % of total dist from start
MIN_TURN_DIST_M   = 5     # metres — minimum straight-line gap between the two final turn
                          #           peaks (buoys are 10 m apart)
# ───────────────────────────────────────────────────────────────────────────

NS_GPX = "http://www.topografix.com/GPX/1/1"
NS_EXT = "http://www.garmin.com/xmlschemas/TrackPointExtension/v2"
NS_XSI = "http://www.w3.org/2001/XMLSchema-instance"

ET.register_namespace("",       NS_GPX)
ET.register_namespace("gpxtpx", NS_EXT)
ET.register_namespace("xsi",    NS_XSI)


# ── helpers ─────────────────────────────────────────────────────────────────

def haversine(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Return distance in metres between two WGS-84 coordinates."""
    R = 6_371_000.0
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi       = math.radians(lat2 - lat1)
    dlambda    = math.radians(lon2 - lon1)
    a = (math.sin(dphi / 2) ** 2
         + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2)
    return 2 * R * math.asin(math.sqrt(max(0.0, a)))


def bearing_diff(a: float, b: float) -> float:
    """Smallest angular difference between two compass bearings (0–180°)."""
    d = abs(a - b) % 360
    return min(d, 360 - d)


def fmt_dist(v: float) -> str:
    if v >= 1000:
        return f"{v / 1000:.3f} km"
    return f"{v:.0f} m"


# ── GPX parsing ──────────────────────────────────────────────────────────────

def parse_gpx(path: str) -> list:
    """Return list of track-point dicts from a VBOX GPX file."""
    ns = {"gpx": NS_GPX, "gpxtpx": NS_EXT}
    
    trkpt_tag = f"{{{NS_GPX}}}trkpt"
    time_tag = f"{{{NS_GPX}}}time"
    speed_tag = f"{{{NS_EXT}}}speed"
    course_tag = f"{{{NS_EXT}}}course"
    ele_tag = f"{{{NS_GPX}}}ele"
    sat_tag = f"{{{NS_GPX}}}sat"

    records = []
    
    for event, elem in ET.iterparse(path, events=("end",)):
        if elem.tag == trkpt_tag:
            t_el      = elem.find(time_tag)
            speed_el  = elem.find(f".//{{{NS_EXT}}}speed")
            course_el = elem.find(f".//{{{NS_EXT}}}course")
            ele_el    = elem.find(ele_tag)
            sat_el    = elem.find(sat_tag)

            t      = datetime.fromisoformat(t_el.text.replace("Z", "+00:00"))
            speed  = float(speed_el.text)  if speed_el  is not None else 0.0
            course = float(course_el.text) if course_el is not None else 0.0
            ele    = float(ele_el.text)    if ele_el    is not None else 0.0
            sat    = int(sat_el.text)      if sat_el    is not None else 0

            records.append({
                "t":      t,
                "lat":    float(elem.get("lat", 0)),
                "lon":    float(elem.get("lon", 0)),
                "speed":  speed,
                "course": course,
                "ele":    ele,
                "sat":    sat,
            })
            
            # Clear the element to prevent massive memory usage
            elem.clear()

    return records


# ── race splitting ───────────────────────────────────────────────────────────

def find_gaps(records: list) -> list:
    """
    Find contiguous idle periods (speed <= IDLE_SPEED_KMH for >= GAP_MIN_SECONDS).
    Returns list of (start_idx, end_idx) tuples.
    """
    gaps      = []
    in_gap    = False
    gap_start = 0

    for i, r in enumerate(records):
        if r["speed"] <= IDLE_SPEED_KMH:
            if not in_gap:
                in_gap    = True
                gap_start = i
        else:
            if in_gap:
                dur = (records[i - 1]["t"] - records[gap_start]["t"]).total_seconds()
                if dur >= GAP_MIN_SECONDS:
                    gaps.append((gap_start, i - 1))
                in_gap = False

    if in_gap:
        dur = (records[-1]["t"] - records[gap_start]["t"]).total_seconds()
        if dur >= GAP_MIN_SECONDS:
            gaps.append((gap_start, len(records) - 1))

    return gaps


def extract_candidates(records: list) -> list:
    """
    Slice the record stream into candidate segments using idle gaps as dividers.
    If a candidate segment represents a multi-lap race (where a competitor runs
    the track several times during one race session, going out and returning to
    shore up to 5 times), we automatically split it into individual laps.
    A lap boundaries are identified by near-shore/beach returns: when the distance to
    the start/end region drops and speed falls low.
    """
    gaps     = find_gaps(records)
    segments = []

    prev_end = 0
    for gs, ge in gaps:
        segments.append((prev_end, gs))
        prev_end = ge + 1
    segments.append((prev_end, len(records)))

    raw_candidates = []
    for start, end in segments:
        seg = records[start:end]
        if not seg:
            continue
        max_spd  = max(r["speed"] for r in seg)
        duration = (seg[-1]["t"] - seg[0]["t"]).total_seconds()
        if max_spd >= MIN_RACE_SPEED and duration >= MIN_RACE_DURATION:
            raw_candidates.append(seg)

    candidates = []
    for seg in raw_candidates:
        # Multi-lap detection: Find returns to shore (beach) within the session.
        # A shore return is defined as: being near the start/end points, combined with low speed.
        n = len(seg)
        if n < 20:
            candidates.append(seg)
            continue

        start_lat, start_lon = seg[0]["lat"], seg[0]["lon"]
        end_lat, end_lon = seg[-1]["lat"], seg[-1]["lon"]

        lap_starts = [0]
        i = 10  # Skip initial start
        while i < n - 10:
            # Check if boat returned near the start/end beach zone (within 25m) and slowed down below 7 km/h
            dist_to_start = haversine(seg[i]["lat"], seg[i]["lon"], start_lat, start_lon)
            dist_to_end = haversine(seg[i]["lat"], seg[i]["lon"], end_lat, end_lon)
            if min(dist_to_start, dist_to_end) < 25.0 and seg[i]["speed"] < 7.0:
                # We found a potential beach return. Ensure we don't split immediately again.
                lap_starts.append(i)
                i += 15  # Cooldown step
            else:
                i += 1

        lap_starts.append(n)

        # Slice into individual laps
        for idx in range(len(lap_starts) - 1):
            lap_seg = seg[lap_starts[idx]:lap_starts[idx+1]]
            if not lap_seg:
                continue
            lap_max_spd = max(r["speed"] for r in lap_seg)
            lap_dur = (lap_seg[-1]["t"] - lap_seg[0]["t"]).total_seconds()
            if lap_max_spd >= MIN_RACE_SPEED and lap_dur >= MIN_RACE_DURATION:
                candidates.append(lap_seg)

    return candidates


# ── turn detection ───────────────────────────────────────────────────────────

def compute_turn_scores(seg: list) -> list:
    """
    Compute a turn score at every point: the bearing difference between
    the course window metres of track before and after that point.
    The window size is dynamically scaled based on the total segment length:
    20% of the segment's length, capped between 20m (for 100m tracks) and 200m (for 1000m+ tracks).
    """
    n = len(seg)

    # Pre-compute cumulative path distance from the first point.
    cum = [0.0] * n
    for i in range(1, n):
        cum[i] = cum[i - 1] + haversine(
            seg[i - 1]["lat"], seg[i - 1]["lon"],
            seg[i]["lat"],     seg[i]["lon"],
        )

    total_dist = cum[-1]
    # Scale window size dynamically (20% of segment distance, between 20m and 200m)
    window = max(20.0, min(200.0, total_dist * 0.20))

    scores = []
    for i in range(n):
        # Reference point behind i: walk backwards until >= window away.
        j = 0
        for k in range(i, -1, -1):
            if cum[i] - cum[k] >= window:
                j = k
                break

        # Reference point ahead of i: walk forwards until >= window away.
        l = n - 1
        for k in range(i, n):
            if cum[k] - cum[i] >= window:
                l = k
                break

        scores.append(bearing_diff(seg[j]["course"], seg[l]["course"]))

    return scores


def detect_turns(seg: list, scores: list) -> tuple:
    """
    Find the two highest-scoring turn points that represent two DISTINCT
    physical buoys.
    """
    n   = len(seg)

    # ── cumulative path distance ───────────────────────────────────────────
    cum = [0.0] * n
    for i in range(1, n):
        cum[i] = cum[i - 1] + haversine(
            seg[i - 1]["lat"], seg[i - 1]["lon"],
            seg[i]["lat"],     seg[i]["lon"],
        )

    total_dist = cum[-1]
    # Scale suppression radius dynamically (15% of segment distance, between 15m and 100m)
    suppress_m = max(15.0, min(100.0, total_dist * 0.15))

    peak1 = max(range(n), key=lambda i: scores[i])

    # ── path-distance suppression around peak1 ─────────────────────────
    def near_peak1(i: int) -> bool:
        return abs(cum[i] - cum[peak1]) < suppress_m

    unsuppressed = [i for i in range(n) if i != peak1 and not near_peak1(i)]

    # ── among unsuppressed, prefer those ≥ MIN_TURN_DIST_M straight-line away ─
    def far_enough(i: int) -> bool:
        return haversine(
            seg[peak1]["lat"], seg[peak1]["lon"],
            seg[i]["lat"],     seg[i]["lon"],
        ) >= MIN_TURN_DIST_M

    far_cands = [i for i in unsuppressed if far_enough(i)]

    if far_cands:
        peak2 = max(far_cands, key=lambda i: scores[i])
    elif unsuppressed:
        # Spatial constraint unachievable — take best of what's left
        peak2 = max(unsuppressed, key=lambda i: scores[i])
    else:
        # Degenerate: whole track is one big loop — pick antipodal point
        peak2 = (peak1 + n // 2) % n

    if peak2 < peak1:
        peak1, peak2 = peak2, peak1

    return peak1, peak2, scores[peak1], scores[peak2]


# ── IRB shape validation ──────────────────────────────────────────────────────

def validate_irb_shape(seg: list, distance_m: float) -> dict:
    """
    Determine whether a segment matches the IRB race track shape.

    A genuine IRB race must satisfy ALL of:
      1. Both turn scores >= MIN_TURN_ANGLE   (two genuine ~180° reversals)
      2. Loop closure   <= LOOP_CLOSURE_PCT % (boat returns to start)
      3. Turn separation >= MIN_TURN_DIST_M   (turns are at distinct buoys,
                                               not two peaks in the same loop)

    Returns a dict with:
      is_real_race    : bool
      t1_idx          : int
      t2_idx          : int
      turn1_score     : float  (degrees)
      turn2_score     : float  (degrees)
      closure_pct     : float  (% of total distance)
      turn_sep_m      : float  (straight-line metres between the two turn points)
      fail_reasons    : list[str]
    """
    scores                    = compute_turn_scores(seg)
    t1_idx, t2_idx, sc1, sc2 = detect_turns(seg, scores)

    closure_m   = haversine(seg[0]["lat"], seg[0]["lon"],
                            seg[-1]["lat"], seg[-1]["lon"])
    closure_pct = (closure_m / distance_m * 100) if distance_m > 0 else 999.0

    turn_sep_m  = haversine(
        seg[t1_idx]["lat"], seg[t1_idx]["lon"],
        seg[t2_idx]["lat"], seg[t2_idx]["lon"],
    )

    fail_reasons = []
    if sc1 < MIN_TURN_ANGLE:
        fail_reasons.append(f"turn1 angle {sc1:.0f}° < {MIN_TURN_ANGLE}°")
    if sc2 < MIN_TURN_ANGLE:
        fail_reasons.append(f"turn2 angle {sc2:.0f}° < {MIN_TURN_ANGLE}°")
    if closure_pct > LOOP_CLOSURE_PCT:
        fail_reasons.append(f"loop not closed ({closure_pct:.1f}% > {LOOP_CLOSURE_PCT}%)")
    if turn_sep_m < MIN_TURN_DIST_M:
        fail_reasons.append(
            f"turns too close together ({turn_sep_m:.0f}m < {MIN_TURN_DIST_M}m)"
        )

    return {
        "is_real_race": len(fail_reasons) == 0,
        "t1_idx":       t1_idx,
        "t2_idx":       t2_idx,
        "turn1_score":  sc1,
        "turn2_score":  sc2,
        "closure_pct":  closure_pct,
        "turn_sep_m":   turn_sep_m,
        "fail_reasons": fail_reasons,
    }


# ── metrics ──────────────────────────────────────────────────────────────────

def race_metrics(seg: list) -> dict:
    """Compute all performance metrics for one race segment."""
    max_speed = max(r["speed"] for r in seg)
    distance  = sum(
        haversine(seg[i]["lat"], seg[i]["lon"],
                  seg[i + 1]["lat"], seg[i + 1]["lon"])
        for i in range(len(seg) - 1)
    )
    duration  = (seg[-1]["t"] - seg[0]["t"]).total_seconds()
    shape     = validate_irb_shape(seg, distance)

    return {
        "start_time":   seg[0]["t"],
        "end_time":     seg[-1]["t"],
        "duration_s":   duration,
        "max_speed":    max_speed,
        "distance_m":   distance,
        "t1_speed":     seg[shape["t1_idx"]]["speed"],
        "t2_speed":     seg[shape["t2_idx"]]["speed"],
        "t1_idx":       shape["t1_idx"],
        "t2_idx":       shape["t2_idx"],
        "is_real_race": shape["is_real_race"],
        "turn1_score":  shape["turn1_score"],
        "turn2_score":  shape["turn2_score"],
        "closure_pct":  shape["closure_pct"],
        "turn_sep_m":   shape["turn_sep_m"],
        "fail_reasons": shape["fail_reasons"],
    }


# ── GPX output ───────────────────────────────────────────────────────────────

def write_race_gpx(seg: list, race_num: int, source_name: str,
                   output_dir: str, is_real: bool) -> str:
    """Write one race segment as a GPX file and return the output path."""
    ts     = seg[0]["t"].strftime("%Y%m%dT%H%M%S")
    prefix = "race" if is_real else "segment"
    name   = f"{prefix}_{race_num:02d}_{ts}.gpx"
    path   = os.path.join(output_dir, name)

    gpx = ET.Element("gpx", {"version": "1.1", "creator": "vxd IRB Race Analyser"})
    gpx.set("xmlns",            NS_GPX)
    gpx.set("xmlns:xsi",        NS_XSI)
    gpx.set("xsi:schemaLocation",
            f"{NS_GPX} http://www.topografix.com/GPX/1/1/gpx.xsd "
            f"{NS_EXT} http://www.garmin.com/xmlschemas/TrackPointExtensionv2.xsd")
    gpx.set("xmlns:gpxtpx", NS_EXT)

    trk        = ET.SubElement(gpx, "trk")
    name_el    = ET.SubElement(trk, "name")
    ts_label   = seg[0]["t"].strftime("%Y-%m-%d %H:%M:%S UTC")
    label      = "Race" if is_real else "Segment"
    name_el.text = f"{label} {race_num} — {source_name} — {ts_label}"
    trkseg_el  = ET.SubElement(trk, "trkseg")

    for r in seg:
        trkpt = ET.SubElement(trkseg_el, "trkpt", {
            "lat": f"{r['lat']:.7f}",
            "lon": f"{r['lon']:.7f}",
        })
        ET.SubElement(trkpt, "ele").text  = str(r["ele"])
        ET.SubElement(trkpt, "time").text = (
            r["t"].strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z"
        )
        ET.SubElement(trkpt, "sat").text = str(r["sat"])
        ext = ET.SubElement(trkpt, "extensions")
        tpe = ET.SubElement(ext, "gpxtpx:TrackPointExtension")
        ET.SubElement(tpe, "gpxtpx:speed").text  = str(r["speed"])
        ET.SubElement(tpe, "gpxtpx:course").text = str(r["course"])

    tree = ET.ElementTree(gpx)
    ET.indent(tree, space="    ")
    with open(path, "w", encoding="utf-8") as fh:
        fh.write('<?xml version="1.0" encoding="utf-8" standalone="yes"?>\n')
        tree.write(fh, encoding="unicode", xml_declaration=False)

    return path


# ── summary CSV ──────────────────────────────────────────────────────────────

def write_summary_csv(all_results: list, output_dir: str) -> str:
    path   = os.path.join(output_dir, "summary.csv")
    fields = [
        "race_num", "is_real_race", "source_file",
        "start_time_utc", "end_time_utc", "duration_s",
        "max_speed_kmh", "distance_m", "distance_km",
        "turn1_speed_kmh", "turn2_speed_kmh",
        "turn1_score_deg", "turn2_score_deg",
        "turn_separation_m", "loop_closure_pct",
        "fail_reasons", "gpx_output",
    ]
    with open(path, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields)
        writer.writeheader()
        for r in all_results:
            m = r["metrics"]
            writer.writerow({
                "race_num":           r["race_num"],
                "is_real_race":       m["is_real_race"],
                "source_file":        r["source_file"],
                "start_time_utc":     m["start_time"].isoformat(),
                "end_time_utc":       m["end_time"].isoformat(),
                "duration_s":         f"{m['duration_s']:.1f}",
                "max_speed_kmh":      m["max_speed"],
                "distance_m":         f"{m['distance_m']:.1f}",
                "distance_km":        f"{m['distance_m'] / 1000:.4f}",
                "turn1_speed_kmh":    m["t1_speed"],
                "turn2_speed_kmh":    m["t2_speed"],
                "turn1_score_deg":    f"{m['turn1_score']:.0f}",
                "turn2_score_deg":    f"{m['turn2_score']:.0f}",
                "turn_separation_m":  f"{m['turn_sep_m']:.0f}",
                "loop_closure_pct":   f"{m['closure_pct']:.1f}",
                "fail_reasons":       "; ".join(m["fail_reasons"]),
                "gpx_output":         r["gpx_path"] if r["gpx_path"] else "",
            })
    return path


# ── console output ───────────────────────────────────────────────────────────

TICK = "✔"
CROSS = "✘"

def print_table(all_results: list) -> None:
    sep = "─" * 130
    print()
    print(sep)
    print(f"{'#':>3}  {'IRB?':^5}  {'Source':<26}  {'Start (UTC)':<20}  "
          f"{'Dur':>5}  {'MaxSpd':>7}  {'Distance':>9}  "
          f"{'T1 Spd':>7}  {'T2 Spd':>7}  {'TurnSep':>8}  Notes")
    print(sep)
    for r in all_results:
        m    = r["metrics"]
        real = TICK if m["is_real_race"] else CROSS
        src  = os.path.basename(r["source_file"])[:26]
        ts   = m["start_time"].strftime("%Y-%m-%d %H:%M:%S")
        dur  = f"{m['duration_s']:.0f}s"
        spd  = f"{m['max_speed']:.0f} km/h"
        dst  = fmt_dist(m["distance_m"])
        t1   = f"{m['t1_speed']:.0f} km/h"
        t2   = f"{m['t2_speed']:.0f} km/h"
        tsep = f"{m['turn_sep_m']:.0f}m"
        note = "; ".join(m["fail_reasons"]) if m["fail_reasons"] else ""
        print(f"{r['race_num']:>3}  [{real}]   {src:<26}  {ts:<20}  "
              f"{dur:>5}  {spd:>7}  {dst:>9}  {t1:>7}  {t2:>7}  {tsep:>8}  {note}")
    print(sep)
    print()


# ── entry point ───────────────────────────────────────────────────────────────

def main() -> None:
    global IDLE_SPEED_KMH, GAP_MIN_SECONDS, MIN_TURN_ANGLE, LOOP_CLOSURE_PCT, MIN_TURN_DIST_M

    parser = argparse.ArgumentParser(
        description="IRB Race GPX Analyser & Splitter",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        "gpx_files", nargs="+", metavar="GPX_FILE",
        help="One or more GPX files from a VBOX session.",
    )
    parser.add_argument(
        "-o", "--output", default="./output",
        help="Output directory for GPX files and summary CSV (default: ./output).",
    )
    parser.add_argument(
        "--real-races-only", action="store_true",
        help=(
            "Only write GPX files for segments that pass IRB shape validation "
            "(two genuine ~180° reversals + closed loop). "
            "Non-race segments are still listed in the console table and CSV "
            "but no GPX file is created for them."
        ),
    )
    parser.add_argument(
        "--idle-speed", type=float, default=IDLE_SPEED_KMH,
        help=f"Speed (km/h) below which the boat is considered idle "
             f"(default: {IDLE_SPEED_KMH}).",
    )
    parser.add_argument(
        "--gap", type=float, default=GAP_MIN_SECONDS,
        help=f"Minimum idle gap (s) that separates races (default: {GAP_MIN_SECONDS}).",
    )
    parser.add_argument(
        "--min-turn-angle", type=float, default=MIN_TURN_ANGLE,
        help=f"Minimum heading reversal at each buoy (degrees) for a valid race "
             f"(default: {MIN_TURN_ANGLE}).",
    )
    parser.add_argument(
        "--max-closure-pct", type=float, default=LOOP_CLOSURE_PCT,
        help=f"Max allowed loop-closure distance as %% of race distance "
             f"(default: {LOOP_CLOSURE_PCT}).",
    )
    parser.add_argument(
        "--min-turn-dist", type=float, default=MIN_TURN_DIST_M,
        help=f"Minimum straight-line distance (metres) between the two detected "
             f"buoy turns. IRB courses have a standardised inter-buoy distance; "
             f"this prevents both peaks being detected inside the same looping turn "
             f"(default: {MIN_TURN_DIST_M}m).",
    )
    args = parser.parse_args()

    IDLE_SPEED_KMH   = args.idle_speed
    GAP_MIN_SECONDS  = args.gap
    MIN_TURN_ANGLE   = args.min_turn_angle
    LOOP_CLOSURE_PCT = args.max_closure_pct
    MIN_TURN_DIST_M  = args.min_turn_dist

    os.makedirs(args.output, exist_ok=True)

    all_results   = []
    seg_counter   = 0
    real_counter  = 0

    for gpx_path in args.gpx_files:
        gpx_path = os.path.abspath(gpx_path)
        print(f"\n📂  Processing: {gpx_path}")

        try:
            records = parse_gpx(gpx_path)
        except Exception as exc:
            print(f"   ⚠️  Failed to parse: {exc}", file=sys.stderr)
            continue

        print(f"    {len(records):,} track points loaded")
        candidates = extract_candidates(records)
        print(f"    {len(candidates)} candidate segment(s) detected")

        source_name = os.path.splitext(os.path.basename(gpx_path))[0]

        for seg in candidates:
            seg_counter += 1
            metrics     = race_metrics(seg)
            is_real     = metrics["is_real_race"]

            write_gpx = is_real or not args.real_races_only
            gpx_out   = None

            if write_gpx:
                if is_real:
                    real_counter += 1
                gpx_out = write_race_gpx(
                    seg, seg_counter, source_name, args.output, is_real
                )

            all_results.append({
                "race_num":    seg_counter,
                "source_file": gpx_path,
                "metrics":     metrics,
                "gpx_path":    gpx_out,
            })

            status = f"[{TICK} IRB RACE]" if is_real else f"[{CROSS} non-race]"
            why    = f"  ← {'; '.join(metrics['fail_reasons'])}" if not is_real else ""
            action = f"→ {os.path.basename(gpx_out)}" if gpx_out else "(no GPX written)"
            print(f"    {status}  #{seg_counter:>2}:  "
                  f"max {metrics['max_speed']:.0f} km/h  |  "
                  f"dist {fmt_dist(metrics['distance_m'])}  |  "
                  f"T1 {metrics['t1_speed']:.0f} km/h  |  "
                  f"T2 {metrics['t2_speed']:.0f} km/h  "
                  f"{action}{why}")

    if all_results:
        csv_path = write_summary_csv(all_results, args.output)
        print_table(all_results)
        print(f"📊  Summary CSV       : {csv_path}")
        print(f"📁  GPX output dir    : {args.output}/")
        print(f"🏁  Confirmed races   : {real_counter}")
        print(f"📋  Total segments    : {seg_counter}")
    else:
        print("\n⚠️  No valid segments found in the provided GPX file(s).")


if __name__ == "__main__":
    main()
