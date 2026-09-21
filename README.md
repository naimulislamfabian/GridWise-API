# GridWise

**LLM-assisted energy scheduling for a smarter campus.**

GridWise converts natural-language operator notes into a cost-optimized, 24-hour energy schedule using grid electricity, solar generation, and battery storage. An LLM interprets the notes; deterministic PHP code validates the resulting directives, solves the scheduling problem, and checks the final plan.

**Live API:** [gridwise-api.wasmer.app](https://gridwise-api.wasmer.app/)

**Health check:** [GET /health](https://gridwise-api.wasmer.app/health)

This is a JSON API. The root URL has no web interface; use the endpoints below.

## Try the live API

```bash
curl https://gridwise-api.wasmer.app/health
```

```json
{"status":"ok"}
```

To optimize a scenario, save the example below as `request.json`, then run:

```bash
curl https://gridwise-api.wasmer.app/optimize-energy \
  -H "Content-Type: application/json" \
  --data-binary @request.json
```

On Windows PowerShell, use `curl.exe` and put the command on one line.

## How it works

```mermaid
flowchart LR
    A[Request validation] --> B[LLM interpretation]
    B --> C[Directive guardrails]
    C --> D[Constraint construction]
    D --> E[Simplex optimization]
    E --> F[Independent plan validation]
    F --> G[JSON response]
```

1. Validate the scenario, 24 hourly forecasts, battery parameters, and operator notes.
2. Interpret each note as one supported directive or a no-op.
3. Check directive types, hours, numeric bounds, and required fields.
4. Convert directives into solar availability, reserve floors, grid caps, and battery operating windows.
5. Minimize grid electricity cost with a two-phase simplex solver implemented in PHP.
6. Replay the returned schedule to verify energy balance, operating constraints, and the final battery state; calculate totals from the plan.

The LLM does not calculate the schedule. Guardrails check the structure and bounds of its output, but do not independently prove that it understood a note correctly.

## API reference

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Basic service health; does not test the LLM provider |
| `POST` | `/optimize-energy` | Interpret notes and return an optimized daily schedule |

### Request

The request must contain exactly these four fields:

| Field | Requirements |
| --- | --- |
| `scenario_id` | Non-empty string returned unchanged in the response |
| `operator_notes` | Array of 1–3 non-empty strings |
| `hours` | Exactly 24 entries, with each integer hour from `0` to `23` present once |
| `battery` | Battery capacity, initial energy, minimum reserve, and hourly charge/discharge limits |

Each hour requires `hour`, `demand_kwh`, `solar_kwh`, and `tariff_bdt_per_kwh`. Energy and tariff values must be finite, non-negative numbers. Hours may arrive out of order; the service sorts them.

The battery requires all five fields shown below. Initial energy and minimum reserve must not exceed capacity, and initial energy must be at least the minimum reserve. Extra fields are rejected at the request, hour, and battery levels.

### Example request

This scenario has constant demand, daytime solar, higher evening tariffs, and a restriction on evening battery charging.

```json
{
  "scenario_id": "CAMPUS-01",
  "operator_notes": [
    "Do not charge the battery between 6 PM and 9 PM."
  ],
  "hours": [
    { "hour": 0, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 1, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 2, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 3, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 4, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 5, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 6 },
    { "hour": 6, "demand_kwh": 100, "solar_kwh": 10, "tariff_bdt_per_kwh": 8 },
    { "hour": 7, "demand_kwh": 100, "solar_kwh": 25, "tariff_bdt_per_kwh": 8 },
    { "hour": 8, "demand_kwh": 100, "solar_kwh": 50, "tariff_bdt_per_kwh": 10 },
    { "hour": 9, "demand_kwh": 100, "solar_kwh": 75, "tariff_bdt_per_kwh": 10 },
    { "hour": 10, "demand_kwh": 100, "solar_kwh": 100, "tariff_bdt_per_kwh": 12 },
    { "hour": 11, "demand_kwh": 100, "solar_kwh": 120, "tariff_bdt_per_kwh": 12 },
    { "hour": 12, "demand_kwh": 100, "solar_kwh": 140, "tariff_bdt_per_kwh": 12 },
    { "hour": 13, "demand_kwh": 100, "solar_kwh": 120, "tariff_bdt_per_kwh": 12 },
    { "hour": 14, "demand_kwh": 100, "solar_kwh": 100, "tariff_bdt_per_kwh": 12 },
    { "hour": 15, "demand_kwh": 100, "solar_kwh": 75, "tariff_bdt_per_kwh": 12 },
    { "hour": 16, "demand_kwh": 100, "solar_kwh": 50, "tariff_bdt_per_kwh": 16 },
    { "hour": 17, "demand_kwh": 100, "solar_kwh": 20, "tariff_bdt_per_kwh": 20 },
    { "hour": 18, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 28 },
    { "hour": 19, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 30 },
    { "hour": 20, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 26 },
    { "hour": 21, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 18 },
    { "hour": 22, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 10 },
    { "hour": 23, "demand_kwh": 100, "solar_kwh": 0, "tariff_bdt_per_kwh": 7 }
  ],
  "battery": {
    "capacity_kwh": 220,
    "initial_energy_kwh": 110,
    "minimum_energy_kwh": 40,
    "max_charge_kwh_per_hour": 50,
    "max_discharge_kwh_per_hour": 50
  }
}
```

### Supported directives

Each note maps to exactly one directive. Use separate notes for separate constraints, within the three-note limit.

| Directive | Adjustment fields | Example note |
| --- | --- | --- |
| `solar_reduction` | `hours`, `factor` | “Only 20% of forecast solar remains from 1 PM to 3 PM.” |
| `minimum_battery_reserve` | `hours`, `minimum_energy_kwh` | “Keep at least 120 kWh in reserve from 6 PM to 9 PM.” |
| `no_charge_window` | `hours` | “Do not charge between 2 PM and 4 PM.” |
| `no_discharge_window` | `hours` | “Do not discharge between midnight and 6 AM.” |
| `max_grid_window` | `hours`, `max_grid_kwh` | “Limit grid import to 80 kWh per hour from 6 PM to 9 PM.” |
| `no_op` | `null` adjustment | “The cafeteria menu changes tomorrow.” |

Time windows include the start hour and exclude the end hour: 1 PM to 3 PM becomes `[13, 14]`. Solar `factor` is the fraction remaining, so an 80% reduction means `0.2`.

Overlapping reserve requirements use the highest floor; overlapping grid caps use the lowest cap. Conflicting solar factors for the same hour are rejected.

### Response

A successful response contains:

| Field | Contents |
| --- | --- |
| `scenario_id` | Original scenario identifier |
| `directive_interpretation` | One interpretation per operator note, in input order |
| `hourly_plan` | 24 entries ordered by hour |
| `total_grid_kwh` | Sum of hourly grid imports |
| `total_cost_bdt` | Sum of hourly grid imports multiplied by their tariffs |
| `peak_grid_kwh` | Largest hourly grid import |
| `plan_summary` | Short description of applied directives and the schedule |

Each interpretation includes `note_index`, `applies`, `directive_type`, `structured_adjustment`, and `explanation`. A `no_op` has `applies: false` and a `null` adjustment.

Each hourly plan entry has the following shape. This is an illustrative entry, not the complete response to the example request:

```json
{
  "hour": 0,
  "grid_kwh": 100,
  "solar_used_kwh": 0,
  "battery_action": "idle",
  "battery_kwh": 0,
  "battery_energy_after_kwh": 110
}
```

`battery_action` is `charge`, `discharge`, or `idle`; `battery_kwh` is the non-negative magnitude of that action. Energy values and aggregate totals are rounded to six decimal places.

### Errors

| Status | Meaning |
| --- | --- |
| `400` | Malformed JSON or invalid request fields |
| `404` | Unknown route or unsupported method |
| `422` | Conflicting directives, infeasible optimization, or failed final plan validation |
| `500` | LLM interpretation/provider failure or an internal error |

Errors return a JSON object such as:

```json
{"detail":"scenario is not safely optimizable"}
```

## Environment configuration

Create `.env` in the repository root:

```dotenv
LLM_PROVIDER=openai_compat
LLM_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai
LLM_API_KEY=your_api_key
LLM_MODEL=your_model_id
LLM_TIMEOUT_SECONDS=8
LLM_REPAIR_ATTEMPTS=1
APP_DEBUG=false
```

Use a model available through your provider. Existing process environment variables take precedence over `.env`.

| Variable | Purpose / default |
| --- | --- |
| `LLM_PROVIDER` | `openai_compat` (default) or `ollama` |
| `LLM_BASE_URL` | Provider base URL; defaults to `https://api.openai.com/v1` or `http://localhost:11434`, respectively |
| `LLM_MODEL` | Required model identifier |
| `LLM_API_KEY` | Required for `openai_compat` |
| `LLM_TIMEOUT_SECONDS` | Per-provider-call timeout, greater than 0 and at most 25 seconds; default `8` |
| `LLM_REPAIR_ATTEMPTS` | Additional attempts after directive-schema validation fails; default `1`, maximum `2` |
| `APP_DEBUG` | Adds an interpretation failure reason to error responses when enabled; default `false` |

For Ollama, set `LLM_PROVIDER=ollama`, `LLM_BASE_URL=http://localhost:11434`, and `LLM_MODEL` to an installed model name. An API key is not required.

### Deployment notes

Keep credentials out of Git and configure the web server to deny access to `.env`, `.git`, and backup archives. The supplied `.htaccess` only routes requests; it does not protect those files. Keep `APP_DEBUG=false` for public deployments.

The application has no built-in authentication or rate limiting. Provider calls can incur usage costs, and the timeout applies to each call rather than the whole request.

## Optimization model and limits

The objective is to minimize:

```text
sum(grid_kwh[h] * tariff_bdt_per_kwh[h]) for h = 0..23
```

The model enforces hourly energy balance, available solar, battery capacity and reserve, charge/discharge rates, applicable operator directives, and end-of-day battery energy equal to the initial energy.

It assumes one-hour intervals and lossless battery storage. It does not model battery degradation, grid export, demand shifting, or forecast uncertainty. Peak grid usage is reported but is not a separate optimization objective.

Current implementation limitations:

- JSON that parses but fails directive validation can trigger a repair attempt. Malformed model JSON and provider failures terminate the request without that repair loop.
- Setting `LLM_REPAIR_ATTEMPTS=0` currently falls back to `1` because of configuration parsing.
- Cache helpers and cache settings exist, but interpretation does not use them; repeated requests still call the provider.
- There is no committed automated test suite or CI workflow.

## Project structure

```text
index.php                 HTTP entry point and response serialization
.htaccess                 Apache rewrite rules
src/
  App.php                 Request orchestration and error responses
  Config.php              Environment loading and provider configuration
  RequestValidator.php    Request schema and numeric validation
  LLM/Interpreter.php     Provider calls, JSON parsing, and repair flow
  Guardrails.php          Structured directive validation
  Directives.php          Directive merging and constraint construction
  Optimizer.php           24-hour linear program and plan construction
  Simplex.php             Two-phase simplex solver
  PlanValidator.php       Independent schedule replay and validation
  Compat.php              PHP 8.0 array-list compatibility helper
  autoload.php            GridWise namespace autoloader
public/
  router.php              Legacy development router
```

## Team

**TheNorseVoyage**

- Tahmidul Haque Tasin
- Naimul Islam Fabian
- Irfan Ul Islam
- Promit Debnath
