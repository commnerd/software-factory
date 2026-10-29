# Software Factory

An autonomous, AI-driven control panel for the whole software lifecycle, for any language/framework.
A **new** project is built once (**Requirements → Architecture → Task breakdown → TDD → QA → Deployment prep**);
after that — and from the start for any **existing** repository — it runs **continuous-improvement cycles**
(**Survey → Backlog review → Plan → TDD → QA → Release → Retrospective**) in which the factory proposes
features and fixes from what it finds in the code and you choose what gets built. Every test run is isolated in a
throw-away container and every critical choice pauses behind a human decision card.

Stack: Laravel 13 · Inertia v3 + Vue 3 · Tailwind 4 · MySQL 8.4 · Laravel Sail (Docker).

---

## 1. Setup (Laravel Sail)

Prerequisites: Docker (with Compose v2), and PHP + Composer **or** just Docker for the very first install.

```bash
cd ~/Workspace/Wellsky/software-factory

cp .env.example .env                 # already present if you used the generated project
# set WWWUSER / WWWGROUP to `id -u` / `id -g` in .env (Sail needs them)

./vendor/bin/sail up -d --build      # app, queue worker, MySQL
./vendor/bin/sail artisan key:generate   # only if APP_KEY is empty
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build      # or: sail npm run dev  (hot reload)
```

Open <http://localhost:8080> (`APP_PORT` in `.env`).

> **No vendor/ yet?** Bootstrap it with Docker only:
> `docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-reqs`

### Moving parts

| Service      | Purpose |
|--------------|---------|
| `laravel.test` | Web app (Vite assets, Inertia pages) |
| `queue`      | `queue:work` — runs `AdvancePipelineJob` and self-iteration jobs. **Pipelines only move while this is up.** |
| `scheduler`  | `schedule:work` — hourly `factory:cycle-due` starts improvement cycles for projects that opted into an interval. |
| `mysql`      | Application database |
| host Docker socket | Mounted into both PHP services so the factory can start sibling containers for installs/tests. `FACTORY_HOST_PROJECT_PATH` (set to `${PWD}` by compose) lets it translate paths for `docker run -v`. |

The Sail image is extended (`docker/8.5/Dockerfile`, `start-container`) with the Docker CLI and group
setup so the `sail` user can use the socket on any host.

### Run without any API key

With empty provider keys the seeder routes every step to the built-in **Fake (offline demo)** model, so the
entire pipeline, all decision gates and the dashboard work immediately. To use real models:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
# and/or
OPENAI_API_KEY=sk-...
OLLAMA_BASE_URL=http://host.docker.internal:11434/v1
```

then `sail artisan db:seed --class=FactorySeeder` (activates providers whose key exists and maps steps to
tiers) and fine-tune in **Models & Routing**.

### Protect it

The factory can run containers and propose changes to itself. Set `FACTORY_ACCESS_PASSWORD` in `.env`
(HTTP Basic auth, any username) before exposing it beyond localhost.

---

## 2. Using it

### Two lifecycles

A project is **built once, then improved continuously**. The factory picks the lifecycle from what the project is:

```
 NEW (no code yet)                               EXISTING repository (code already there)
 ─────────────────                               ────────────────────────────────────────
 BUILD lifecycle                                 Import & Assessment ──(you confirm)──┐
 Requirements → Architecture ⟶ you approve       (gaps become the first backlog)      │
 Task breakdown → TDD → QA                                                            │
 Deployment prep ⟶ you approve                                                        │
        │                                                                             │
        └──────────── project idles, "released" ──────────────────────────────────────┤
                                                                                      ▼
                       IMPROVE lifecycle — numbered cycles, forever
        ┌─► Survey & Propose ─► Backlog Review ⟶ you choose ─► Cycle Planning ─► TDD ─► QA ─►
        │   (AI reads the code,   (tick what this cycle                                      │
        │    proposes features,    builds; rest stays                  Release Prep ⟶ you approve
        │    fixes, tests, …)      in the backlog)                                           │
        │                                                                          Retrospective
        └──────────── project idles; next cycle starts by button or schedule ◄───────────────┘
```

1. **Projects → New project** — brief, language/stack, optional repo + budget. *Create & start* queues the pipeline.
2. **New project:** the pipeline writes requirements, designs the architecture, and **stops at an Architecture approval card**.
   Rejecting regenerates it with your note as feedback. After approval: task breakdown → **TDD per task** → full-suite **QA** →
   generated **GitHub Actions** workflows → **Deployment approval card**. Approving commits locally; the project then **idles as
   "released"** and the **Start improvement cycle 1** button appears.
3. **Existing repository:** see the next section; after you confirm the assessment it goes straight into cycle 1.
4. **Approvals** shows every pending decision (assessment, backlog selection, architecture, cost override, deployment,
   self-modification, stuck task / failed QA). The sidebar badge counts them.
5. Project page tabs: Pipeline docs · **Backlog** (proposals, cycle history, add your own idea) · Tasks (red→green→refactor bars) ·
   Tests (container output) · Agent log (compiled prompt, response, **agent thoughts**) · Files · Models · Settings.

### Continuous improvement cycles

Each cycle is one pass of the improve lifecycle, numbered 1, 2, 3 …:

| Stage | What happens | Human? |
|-------|--------------|--------|
| **Survey & Propose** | Pulls upstream commits if the branch tracks a remote, re-inventories the code, runs the tests (health check), and asks the `feature-surveyor` skill for new features, enhancements, bug fixes, missing tests, refactors, performance/security work, docs, dev-experience and dependency upgrades — each with evidence, impact (1–5), effort (1–5), acceptance criteria. It sees the open backlog, everything built/rejected/failed before (so it never repeats itself) and the last retrospectives. | — |
| **Backlog Review** | A decision card lists every open proposal (new + carried over) ranked by value-for-effort (`impact×2 ÷ effort`) with the top *N* pre-ticked (`FACTORY_CYCLE_CAPACITY`, default 3). Tick what to build. Unticked items stay as *deferred*. Choosing nothing closes the cycle. Reject + note = "re-survey with this focus". | **yes** |
| **Cycle Planning** | Splits only the chosen proposals into small tasks (one sprint per cycle), each linked to its proposal. | — |
| **TDD Implementation** | Same strict red → green → refactor engine (see below); `tests` proposals use characterization mode. | on stuck |
| **QA** | Full suite in a clean container + AI review; anything but 100 % green stops for a decision. | on failure |
| **Release Prep** | Adds only the CI workflows that are missing (existing ones are never overwritten). Approving commits the cycle to the local branch **`factory/cycle-N`** — cumulative, main line untouched, never pushed. | **yes** |
| **Retrospective** | Marks proposals *done* / *failed* (blocked tasks ⇒ failed), records shipped/not-shipped/blocked/cost/rejections, and writes lessons + "next survey focus" that the next survey reads. | — |

The project then goes **idle**. Start the next cycle with the **Start improvement cycle N** button, or opt in to a schedule in
*Settings → Auto-start every N days* (the `scheduler` service runs `factory:cycle-due` hourly). A scheduled cycle only
**surveys**; it then waits at the Backlog Review — nothing is built without a human choosing it.

The **Backlog** tab is yours to curate: add ideas (they compete with the AI's), dismiss proposals permanently, restore failed
or dismissed ones. Every stage record, sprint and task belongs to its cycle, so history is never overwritten; requirements and
architecture from the original build (or recovered during assessment) stay available to every later cycle.

### Existing repositories — assess, then improve; never rebuild

Give a project a repository URL (optionally a branch; language can be *Auto-detect*) and the first stage,
**Import & Assessment**, runs before anything is generated:

1. **Import** — `git clone` (shallow) into the workspace. Only `https://`, `ssh://`, `git@…` sources are accepted;
   local paths only under `FACTORY_IMPORT_LOCAL_ROOTS`. For private GitHub repos over HTTPS set `GITHUB_TOKEN`
   (never stored in `.git/config`). An empty repository is treated as greenfield.
2. **Inventory** — facts gathered by tooling: stack/framework from manifests, README/docs, test files, CI workflows,
   TODO count, recent commits; the most useful files are mirrored into the project so agents can read them.
3. **Baseline** — the repository's own tests are run once, in a container, before anything is touched
   (`FACTORY_IMPORT_BASELINE=false` to skip; the same health check runs at the start of every survey).
4. **Assessment** — the `project-assessor` skill judges requirements / architecture / implementation / QA / deployment as
   `complete | partial | missing` with evidence, and recovers requirements and architecture documents where it can.
5. **You confirm** — a *Confirm assessment* card shows the findings. Confirming does **not** run the build lifecycle:
   recovered documents become project knowledge, and every gap (missing docs, no CI, untested code, unbuilt features the
   assessor found) becomes a **backlog proposal for cycle 1**. The first survey then adds its own and you choose what to build.
   Rejecting with a note re-assesses.

*Characterization* work (existing behaviour with no tests) has no red phase — those tests must **pass** against the current
code, which then protects it during later cycles.

### Strict TDD, enforced in code (`app/Services/Pipeline/TddEngine.php`)

| Phase | Rule |
|-------|------|
| Red | Tests are generated first and run in a container. If they **pass** with no implementation they are rejected and regenerated; after 2 tries the task is escalated to a human. |
| Green | Implementation iterates (default 4 attempts) with the failing output fed back. **Test files are frozen** — any attempt to edit them is dropped. Zero parsed tests never counts as green. |
| Refactor | Must keep 100 % green; otherwise the refactor is **reverted** from a snapshot. |
| QA | Whole suite re-run in a clean container; anything but 100 % green (or an AI-review "fail") opens an intervention gate. |

### Budgets

Each project has a USD limit. Every LLM call is costed from the model's per-Mtok prices. Hitting the limit
pauses the pipeline behind a **cost-override** card (approve with a new limit, or keep it paused).

---

## 3. Granular model selection

`ModelRouter` resolves the model for each **step** (`requirements_analysis`, `architecture_design`,
`task_breakdown`, `tdd_red`, `tdd_green`, `tdd_refactor`, `qa_review`, `ci_workflow`, `deployment_prep`,
`self_analysis`, `self_implementation`) in this order:

1. per-project route (project → *Models* tab)
2. global route (**Models & Routing** matrix; optional fallback model tried if the primary errors)
3. an active model of the step's default tier (cheap for parsing/scaffolding, standard for refactor/QA, frontier for design and code)
4. the model flagged *default*

Providers are adapters behind `App\Services\Ai\Contracts\AiProvider`: `anthropic` (incl. extended-thinking budget),
`openai` (chat-completions protocol — also OpenRouter/vLLM/LM Studio via `OPENAI_BASE_URL`), `ollama`, `fake`.
To wrap another SDK (e.g. the Laravel AI SDK), add a class implementing `AiProvider` and a
`create<Name>Driver()` method in `ProviderManager`.

## 4. Database-driven AI skills

Skills live in `ai_skills` + immutable `ai_skill_versions`. Nothing is cached: the next agent call uses
whatever is live.

* **Skills** page: edit system instructions and prompt template → *Publish new version*; roll back with *Make live*.
* Templates: `{{ var }}`, dot paths `{{ project.language }}`, arrays rendered as JSON, and `{{> snippet-slug }}`
  to include a shared snippet (e.g. `house-rules`).
* **Playground** tests the *unsaved* editor contents on any model with sample JSON variables, shows the compiled prompt,
  response, thoughts, tokens, cost and whether JSON output parses — without touching versions or project budgets.
* Disable a skill to take its step offline; steps fail loudly if no active skill is bound.

## 5. Self-iteration (meta-programming)

**Self-Iteration** → *Analyse my own codebase*. The `self-analyst` skill reads a bounded view of this repo and files
proposals. *Implement & test* then: implements test-first → copies the repo into `storage/app/staging/<id>` →
applies the change → runs `php artisan test` in a container (`FACTORY_SELF_IMAGE`) → opens a **Self-modification**
card showing the diff. Approval only **stages** `storage/app/staged/<id>/changes.patch`; you apply it deliberately:

```bash
./vendor/bin/sail artisan factory:apply-staged <id>
```

Guard rails: only `app/ config/ database/ resources/ routes/ tests/ README.md` may change; `.env`, `vendor/`, `storage/`,
`.git/` never; PHP changes without tests are rejected.

## 6. Universal language support

`config/factory.php → languages` maps a stack key to a container image, install/test commands and GitHub Actions
setup steps (PHP, Node, Python, Go, Java, .NET, Ruby, Rust included). Override any value per project in
*Settings*, or add a new stack by adding an entry. Containers run as the app's uid with caches under `/tmp`,
memory/CPU limits, and a timeout. `TestResultParser` understands PHPUnit/Pest, Jest/Vitest, pytest, Go, Maven, dotnet, RSpec and cargo output.

CI generation (`CiWorkflowGenerator`) emits `.github/workflows/ci.yml` (test → build) and `deploy.yml`
(manual dispatch with environment gate) from a language-aware baseline, then lets the `ci-workflow-engineer`
skill refine them; invalid AI output falls back to the baseline.

---

## 7. Layout

```
app/Enums/                 SdlcStage, FactoryStep, GateType, TddPhase, ModelTier
app/Models/                Project, ProjectStage, Sprint, ProjectTask, TestSuite, AgentRun, ApprovalGate,
                           ModelConfiguration, ModelRoute, AiSkill, AiSkillVersion, ProjectArtifact, SelfProposal
app/Services/Ai/           ProviderManager, ModelRouter, SkillResolver, SkillCompiler, AgentRunner, Providers/*
app/Services/Execution/    Executor (Docker/Fake), Workspace, TestRunner, TestResultParser, PathMapper
app/Services/Pipeline/     PipelineOrchestrator, Stages/*, TddEngine, GateService, GateDecisionHandler, CiWorkflowGenerator
app/Services/SelfIteration/ SelfIterationService
app/Jobs/                  AdvancePipelineJob, ProcessSelfProposalJob
resources/js/pages|Components|Layouts   Inertia + Vue dashboard
database/migrations, database/seeders   schema, starter models/skills/routes
config/factory.php         executor, budgets, providers, language profiles, self-iteration policy
```

Commands: `factory:run {project}` (synchronous pipeline, handy for debugging), `factory:apply-staged {id}`.

## 8. Tests

```bash
./vendor/bin/sail artisan test
```

Runs against SQLite in memory with the fake provider and fake executor (no Docker, no API keys). Covers skill
compilation/versioning, model routing precedence, the full SDLC with gates, TDD ordering, budget gating,
workspace safety, CI generation, self-iteration guard rails and every dashboard page.

## 9. Known limits

* The dashboard live-updates by polling (2–5 s) rather than WebSockets.
* "Deployment" commits the generated workspace locally and prepares workflows; pushing to GitHub / provisioning
  infrastructure is intentionally left to you (secrets belong in GitHub environments).
* Model ids and prices seeded in `FactorySeeder` are starting points — edit them under **Models & Routing**.
