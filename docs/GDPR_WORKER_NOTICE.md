# Worker PWA — Geolocation & Selfie Privacy Notice

**Status: DRAFT for the client's data-protection lawyer to review and finalise.**
Nothing here is legal advice. It is the developer's working draft of (a) the
information the Worker mobile app collects, (b) how the app implements Spain's
information duty, and (c) the specific decisions the client + lawyer must confirm
before go-live. Two of those decisions were flagged open in `CLAUDE.md` and are
resolved here only as **defaults to be confirmed**, not as final legal text.

Prepared 2026-07-23.

---

## 1. Why this exists

The Worker PWA captures, on each punch:

- a **GPS location fix** at check-in and at check-out;
- a **live selfie photo** at check-in;
- the **date and time** of each punch (the working-time record).

Location and a photograph of an identifiable person are **personal data**; the
selfie is a photograph but is **not** processed as biometric data (we do not run
facial recognition or template-matching — it is a visual presence check only).
Because this is workforce monitoring, Spanish law imposes a **prior information
duty** on the employer. This app is built to discharge that duty in-product.

## 2. Lawful basis — NOT consent

Under the GDPR (Reg. 2016/679), the LOPDGDD (LO 3/2018) and the Estatuto de los
Trabajadores, an employee's "consent" to being monitored is generally **not a
valid lawful basis**, because it cannot be freely given in a subordinate
relationship (AEPD / EDPB doctrine). The processing rests instead on:

- **Art. 6(1)(b) GDPR** — performance of the employment contract;
- **Art. 6(1)(c) GDPR** — the employer's **legal obligation** to keep a daily
  working-time record (**Real Decreto-ley 8/2019**, *registro de jornada*);
- **Art. 20.3 Estatuto de los Trabajadores** — the employer's power to monitor
  the fulfilment of work duties, exercised with respect for dignity;
- **Art. 90 LOPDGDD** — geolocation devices in the workplace, which **requires
  the employer to inform workers (and their representatives) expressly, clearly
  and unambiguously, in advance**.

**Implication for the app (updated 2026-08-11 — HYBRID model).** The processing
is split by purpose, because the three data items do NOT share one lawful basis:

- **Attendance time record (date/time)** — mandatory (art. 6(1)(c) + RD-ley
  8/2019). The worker cannot decline it and keep working; the app therefore
  presents it as an **acknowledgement of the information duty**, not a refusable
  consent. This is the mandatory checkbox.
- **GPS location** and **selfie photo** — made genuinely **OPTIONAL**: the app
  works fully without either (the worker punches in without a fix / without a
  selfie, with no penalty). Because declining carries **no detriment**, consent
  here **is** freely given and IS a valid basis (art. 6(1)(a) + art. 7). Each is
  its **own separate checkbox** the worker can grant, withhold, and later revoke.

Framing the *mandatory record* as "consent" would be legally wrong; but the two
*optional* extras are exactly the case where consent is the correct basis. This
split is what the two-checkbox screen and the `worker_consents` evidence table
implement.

> **Lawyer to confirm:** that legitimate-interest / legal-obligation is the basis
> the client wants recorded in the Registro de Actividades de Tratamiento (RAT),
> and that a **prior consultation with the workers' legal representatives**
> (art. 90.1 LOPDGDD) has taken place / been documented where representatives
> exist.

## 3. How the app enforces the information duty

- A worker cannot reach any punch until they have been shown the notice and
  tapped **"He leído y entiendo / I have read and understand."**
- The acknowledgement is stored on the worker's employee record
  (`privacy_notice_ack_at` + `privacy_notice_ack_version`) and the write is
  recorded in the **append-only audit log** — this is the evidence the employer
  informed the worker, and when.
- The notice is **versioned** (`App\Support\WorkerPrivacyNotice::VERSION`). If the
  notice text changes materially (new data captured, new recipient, changed
  retention), bump the version and **every** worker is re-shown and must
  re-acknowledge before their next punch.
- The gate is enforced **server-side** as well as in the UI: a crafted request to
  the punch endpoint without a current acknowledgement is refused (HTTP 403).
- **Location refusal is not a block.** If the worker denies location permission,
  the punch is still recorded and flagged `location_denied` — GPS is *evidence*,
  never a gate. The notice tells the worker this.

The notice text the worker sees lives in the translation files
(`lang/es/ui.php` + `lang/en/ui.php`, keys `worker.privacy.*`), bilingual
Spanish-primary / English-secondary, and is reproduced in §4.

## 4. The notice text (as currently shipped — for review)

> **Protección de datos — Antes de fichar, lea esta información**
>
> Esta aplicación registra su jornada laboral en la obra.
>
> **Qué datos se recogen**
> - Su ubicación (GPS) al fichar la entrada y la salida.
> - Una fotografía suya (selfie) al fichar la entrada.
> - La fecha y la hora de cada fichaje.
>
> **Para qué** — Para cumplir el registro de jornada obligatorio y confirmar su
> presencia en el centro de trabajo. La base es su relación laboral y las
> obligaciones legales de la empresa (RD-ley 8/2019; LOPDGDD art. 90). No se le
> pide su consentimiento: es información obligatoria.
>
> **Quién los ve** — Únicamente el personal autorizado de su empresa, para la
> gestión de su jornada y su nómina.
>
> **Cuánto tiempo se conservan** — El registro de jornada se conserva durante el
> plazo legal (4 años). La ubicación y la fotografía se conservan durante el
> tiempo imprescindible conforme a la política de conservación de su empresa.
>
> **Sus derechos** — Puede solicitar el acceso, la rectificación o la supresión
> de sus datos, y presentar una reclamación ante la Agencia Española de
> Protección de Datos (AEPD). Para ejercerlos, diríjase a su empresa.
>
> *Si deniega la ubicación, su fichaje se registrará igualmente, indicando que no
> se pudo obtener.*
>
> **[ He leído y entiendo esta información ]**

(English secondary text ships alongside every line.)

## 5. Decisions the client + lawyer MUST confirm before go-live

These are the open items. The app ships defensible **defaults**; finalising any
of them that changes the notice text means **bumping the notice version** so
workers re-acknowledge.

1. **Data controller identity.** Each of the five companies (Contalex 365,
   Alovar, Shizukani, Grupo Verto 5, Malaga) is a separate controller for its own
   workers. Confirm the exact registered name + CIF + contact to name in the
   notice, and whether a **DPO (Delegado de Protección de Datos)** is appointed
   and must be named.
2. **Retention period for location + selfie.** The working-time record is fixed
   at **4 years** by law (RD-ley 8/2019 / Inspección de Trabajo). The **GPS fix
   and the selfie** are *not* fixed by that rule. Decide the minimum necessary
   retention (e.g. purge selfies + coordinates after N months once the punch is
   reconciled to payroll) and set it as policy. The app currently states "the
   minimum time necessary, under your company's retention policy" — replace with
   the concrete period once decided, and implement the purge job.
3. **Rights-exercise contact.** A concrete channel (email / address / person) for
   access-rectification-erasure requests, to name in the notice instead of the
   generic "contact your company."
4. **Representatives' consultation.** Evidence that the workers' representatives
   were informed/consulted per art. 90.1 LOPDGDD where they exist.
5. **RAT entry.** Add these processing activities (working-time record;
   geolocation; presence photo) to each company's Registro de Actividades de
   Tratamiento, with basis, retention, recipients, and (if applicable) the
   **Data Protection Impact Assessment** — geolocation of workers is a candidate
   for a DPIA under the AEPD's list.
6. **Hosting / transfers.** Confirm the London (UK) hosting posture for this
   personal data — already noted GDPR-acceptable in `docs/STACK.md`; confirm it
   covers worker geolocation + images specifically, and that the UK adequacy
   position is documented.

## 5b. Consent evidence record — the two-checkbox model (implemented 2026-08-11)

Every consent decision is stored as an **append-only** row in `worker_consents`
(legal evidence; the history is never destroyed — a change/revocation writes a new
row and stamps `revoked_at` on the superseded one). Each row captures:

`employee_id` · `user_id` · `company_id` · `consent_version` (e.g. `v1.0-2026-08`)
· `ip_address` · `user_agent` (browser + device) · `consented_at` (exact instant)
· `timezone` (Europe/Madrid) · `consent_attendance` (mandatory ack) · `consent_gps`
(optional) · `consent_photo` (optional) · `consent_text_shown` (the FULL exact
text the worker saw, snapshotted server-side so it is self-contained) · `language`
(es/en) · `revoked_at` · `revoked_reason`.

- **Worker screen:** three separate checkboxes; the Accept button is disabled
  until the worker has scrolled the whole notice AND ticked the mandatory
  attendance acknowledgement. GPS and selfie default on but can be unticked.
- **Check-in flow:** no GPS consent → no fix requested, `location_captured=false`,
  and no GPS-missing alert; no selfie consent → no camera, no selfie stored.
- **Revocation:** the worker toggles GPS/selfie off from the PWA ("Privacy &
  consent" panel) at any time — as easy to withdraw as to give (art. 7(3)).
- **Version bump:** Settings → Legal holds the current version; bumping it
  (Company/Super Admin) invalidates every active consent, so all workers must
  re-accept before their next punch.
- **Admin (Employee Detail):** consent status, date/time, IP, device, which boxes
  were ticked, version, the full history, a **PDF export** of any record (gated +
  audited), and a **Reset** button to force one worker to re-accept.

## 6. What is already implemented (no further build needed)

- In-app pre-punch two-checkbox consent screen (scroll-gated) + server-enforced
  gate; full `worker_consents` evidence record (§5b).
- Versioned notice with automatic re-acceptance on a version bump (admin-managed
  in Settings → Legal).
- Consent records are `Auditable`; the append-only table is the evidence trail.
- GPS + selfie are optional, freely revocable, and the app works without them.
- Selfies stored on the **private** disk, served only through an authenticated,
  permission-checked, audited admin route (never a public URL).
- Location stored as coordinates on the attendance row; shown to admins as a
  Google Maps link behind the `attendance.view` right. A poor-accuracy fix
  (>1 km) is never used as the check-out mismatch anchor.
- Location refusal / opt-out recorded, never blocking the punch.

## 7. What is NOT yet built (depends on the §5 decisions)

- The **retention purge job** for selfies + coordinates (needs the period in §5.2).
- Naming the **controller / DPO / rights contact** in the notice (needs §5.1, §5.3)
  — currently generic wording; finalising it is a lang-file edit + a version bump.
- The **RAT entry** and any **DPIA** (client/legal deliverables, not app code).
