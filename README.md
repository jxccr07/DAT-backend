# Digital Academic Twin — API

> **التوأم الأكاديمي الرقمي**: منصة إرشاد أكاديمي تُري الطالب **ثمن القرار** قبل أن يتخذه، مثل حذف مادة أو تخفيف العبء، بمقارنته بآلاف الطلاب الذين كانوا في موقفه نفسه. وتساعد المرشد على الوصول للطالب المناسب في الوقت المناسب.

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Tests: Pest](https://img.shields.io/badge/tests-Pest-F28D1A)

**Live demo:** [dat.ksa.moe](https://dat.ksa.moe) · **Web app:** [DAT-frontend](https://github.com/jxccr07/DAT-frontend)

This repository is the Laravel API behind the Digital Academic Twin. The web app talks to it from its own server; the API is not meant to be exposed to the internet directly.

---

## The idea

Students often drop a course or take a light semester without knowing what it usually costs them. The twin answers one question at the moment of the decision:

> *"Out of 100 students like me who made this choice, how many fell behind their study plan two terms later?"*

"Like me" means the same stage in the plan, the same GPA band and the same distance from the plan. Numbers for a specific course are only shown when at least 100 comparable students exist, so every figure is backed by real history rather than a guess.

## Features

**For students**
- **Academic status**: progress against the study plan, risk level and what students who caught up actually did.
- **Price of a decision**: before dropping a course, see the outcome for comparable students, with that course's own numbers when there is enough history.
- **Time machine**: project the path to graduation term by term, including summer terms.
- **GPA what-if** and a full academic record.
- **Course registration** with the plan's rules (minimum/maximum load, open courses, failed courses).
- **Messages** with their academic advisor and **meeting requests**.

**For advisors**
- Only their own students, ranked by need: new decision alerts first, then highest risk, then furthest behind.
- **Weekly priorities** and a **critical tier** (the top 10% of their students).
- **Decision alerts** when a student goes ahead with a costly drop, with the same numbers the student saw (sent only with the student's consent).
- **Talk before the drop deadline**: a short daily list of students worth a conversation before it is too late.
- Private notes, in-portal messaging and a 7-day activity summary.

**The twin model**
- A cohort lookup built from academic records (`php artisan twin:build`).
- Accuracy is measured on 20% of students held out from the build, against a simple baseline, and reported with calibration on the methodology page.

## Architecture

```
Browser ──▶ Next.js web app (BFF) ──▶ Laravel API (this repo) ──▶ SQLite
            · renders the pages          · authentication (Sanctum tokens)
            · keeps the token in an       · business rules and the twin model
              httpOnly cookie             · advisor alerts, messaging, demo mode
```

The browser never sees the API address or the token. Every request is checked twice: once by the web app and again by the API.

## Tech stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13, PHP 8.3+ |
| Auth | Laravel Sanctum (personal access tokens with expiry) |
| Database | SQLite (WAL mode) |
| Tests | Pest |
| Code style | Laravel Pint |

## Getting started

**Requirements:** PHP 8.3+ with `sqlite3`, `mbstring`, `xml`, `curl`, `intl`, `bcmath`, `zip`, and Composer.

```bash
git clone https://github.com/jxccr07/DAT-backend.git
cd DAT-backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # generates the synthetic dataset (large; takes a while)
php artisan advising:assign         # creates advisors and assigns students to them
php artisan twin:build              # builds the twin model (--students=N for a quick build)
php artisan serve --port=8000
```

Then run the [web app](https://github.com/jxccr07/DAT-frontend) with `API_URL=http://127.0.0.1:8000/api/v1`.

### Demo mode

The public demo lets visitors try the portal in one click without real accounts.

| Setting / command | Purpose |
|---|---|
| `DEMO_MODE=true` | Enables the one-click demo student and demo advisor sign-ins |
| `DEMO_ADVISOR_EMAIL` | Pins the advisor whose students the demo uses |
| `DEMO_PASSWORD` | The demo accounts' password (16+ random characters) |
| `php artisan demo:secure-accounts` | Locks every account except the demo advisor and their students |
| `php artisan demo:tidy` | Runs every minute: removes what visitors wrote after 10 minutes and restores the demo students' registrations |
| `php artisan advising:demo-scenario` | Prepares a demo advisor with real decision alerts |

## Useful commands

| Command | What it does |
|---|---|
| `php artisan advising:assign` | Creates advisors and assigns students (default caseload 300) |
| `php artisan twin:build` | Builds the twin tables and measures accuracy on held-out students |
| `php artisan test` | Runs the test suite |
| `./vendor/bin/pint` | Formats the code |

## API overview

All routes live under `/api/v1` and answer JSON. Signed-in routes need `Authorization: Bearer <token>`.

| Group | Routes |
|---|---|
| Auth | `POST /me/login`, `POST /me/logout` |
| Account | `GET /me` |
| Student | `/me/schedule`, `/me/academic/{plan,term,grades}`, `/me/registration`, `/me/messages`, `/me/advisor-request` |
| Twin | `/me/twin`, `/me/twin/decision`, `/me/twin/timeline`, `/me/twin/meta` |
| Advisor | `/me/advisor`, `/me/advisor/students`, `/me/advisor/students/{id}`, notes, messages, alerts, `/me/advisor/before-deadline` |
| Demo | `/demo-student`, `/demo-advisor` (only when `DEMO_MODE=true`) |

Student routes only accept student accounts and advisor routes only accept advisors; an advisor only reaches their own students.

## Security

- Token authentication with expiry; login is rate-limited per account and per IP, and answers the same way, in about the same time, whether or not the email exists.
- Every query is scoped to the signed-in user, and every input is validated.
- Debug output is off in production, and the API is designed to sit behind the web app on a private interface.

Found a security issue? Please report it privately through GitHub's **Report a vulnerability** button on this repository instead of opening a public issue.

## Data

All data is **synthetic**, generated by the seeders in `database/seeders`. Names, numbers and grades do not belong to real students.

## Project structure

```
app/
  Console/Commands/   twin:build, advising:*, demo:*
  Http/Controllers/   auth, student, registration, twin, advisor, messaging, demo
  Services/           registration rules, advising, messaging, drop deadline
  Twin/               the twin model and its queries
  Support/            demo accounts and registrations
routes/api.php        all API routes
tests/Feature/        Pest feature tests
```

## License

Licensed under the [Apache License 2.0](LICENSE).
