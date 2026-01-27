# AGENTS.md

These instructions are mandatory for any agent working in this repository. They are tuned to keep the codebase typesafe, clean, reusable, extendable, performant, and maintainable.

## Project snapshot (do not ignore)

- Framework: Laravel 12, PHP 8.3, API-only.
- Auth: Laravel Sanctum (token auth).
- API versioning: grazulex/laravel-apiroute (routes in `routes/api/v{n}.php` and config in `config/apiroute.php`).
- DTOs: spatie/laravel-data (`app/Data`).
- Enums: spatie/laravel-enum (`app/Enums`).
- Query building: spatie/laravel-query-builder (`config/query-builder.php`).
- API docs: dedoc/scramble (`config/scramble.php`).
- TypeScript types: spatie/laravel-typescript-transformer -> `resources/types/generated.d.ts`.
- Quality: PHPStan (max), Rector, Pint (strict rules).
- Tests: Pest (feature tests in `tests/Feature/Api/V1`).

## Non-negotiables

1. Every PHP file must have `declare(strict_types=1);` at the top.
2. Classes are `final` by default. Use `abstract` only when required by design.
3. Controllers must stay thin: validate/transform input -> call action/service -> return Resource via `ApiResponse`.
4. This is API-only. Do not add Blade, Vite, or frontend assets.
5. Never edit `vendor/` or `storage/` contents.
6. Never add unnecessary comments unless they explain "why" something is done a certain way.

## Scope and definition of done

- Backend remains end-to-end type safe; backend is the source of truth for shared types.
- No duplication is introduced; shared logic is extracted.
- Naming is domain-accurate, consistent, and deliberate.
- Code is formatted/refactored to match configured tooling.
- Static analysis and type checks pass (see Quality gates).
- Follow Laravel and kit best practices; do not introduce ad-hoc patterns.

## Single source of truth

- Backend owns business logic and state transitions; frontend renders data and triggers actions.
- Any logic involving rewards, limits, eligibility, status transitions, conversion rates, or authorization must be implemented server-side.

## No ambiguity, no loose structures

- Avoid associative arrays (or free-form objects) across layers.
- Prefer DTOs and enums at boundaries; avoid optional/mixed payload shapes unless the contract explicitly allows it.
- This applies to controller -> action/service boundaries, service -> job boundaries, and external provider integrations.

## No duplicate code

- Reuse existing logic and abstractions.
- If similar logic appears more than once, extract a shared abstraction.
- Prefer generic, reusable building blocks when patterns repeat.

## Naming is first-class

- Names must reflect the domain and use cases.
- Names must be consistent across backend and frontend (DTOs, enums, API endpoints, component names).
- File paths must match conceptual ownership (domain/module/provider).

## Strict and explicit typing

- Every public method must have explicit parameter and return types. Use phpdoc only when PHP cannot express the type (array shapes, generics).
- Avoid ambiguous return values; prefer explicit result DTOs and typed exceptions mapped by a global handler.
- Prefer `readonly` constructor properties for injected dependencies.
- Prefer value objects and enums over ad-hoc strings/ints.

## Architecture and layering

- Controllers live in `app/Http/Controllers/Api/V{n}` and extend `App\Http\Controllers\Api\ApiController`.
- Validation lives in `app/Http/Requests/Api/V{n}` (FormRequest). Do not validate inline.
- DTOs live in `app/Data/*Data` (Spatie Data).
- Enums live in `app/Enums` and are used via casts (`Model::casts()`).
- Complex business logic belongs in `app/Actions` or `app/Services` (create these directories if needed).

## API versioning rules

- Do not add routes to `routes/api.php`.
- Add routes in `routes/api/v{n}.php`.
- Use URI-based versioning unless explicitly approved otherwise.
- Register new versions in `config/apiroute.php` and keep lifecycle metadata (status, deprecated_at, sunset_at, successor) accurate.
- Name routes with `api.v{n}.*`.
- When introducing breaking changes, create a new version rather than changing old behavior.
- Deprecations must include RFC-compliant headers when applicable (Deprecation, Sunset, Link).
- Version prefix and compatibility rules must follow `README.md`.

## Auth, security, and rate limits

- Protected routes must use `auth:sanctum`.
- Apply throttles: `throttle:auth` for login/register, `throttle:authenticated` for authenticated routes.
- Never return secrets (passwords, tokens, remember_token) in resources.
- Always hash passwords with `Hash::make` and verify with `Hash::check`.
- Assume hostile input; enforce authorization via policies/guards in addition to authentication.

## Data, enums, and types

- Every Eloquent model must have a matching DTO in `app/Data/{Model}Data.php`, with stateful attributes modeled as enums.
- All API inputs and outputs must be expressed as Data DTOs; controllers map Request -> Data -> action/service -> Resource.
- DTOs are the contract and must remain stable. Breaking changes require explicit approval (see `README.md`).
- Prefer small, composable Data objects; use nested Data objects instead of nested arrays.
- Use explicit nullability (`?Type`) instead of "sometimes missing" semantics.
- DTOs must declare explicit validation rules for every attribute (required/nullable, format, bounds, regex, enum).
- Add `#[TypeScript]` to DTOs and enums you want exported.
- Any state/status/type/level/permission/provider/mode must be an enum.
- Never compare enum values to raw strings; encapsulate logic in enum methods when needed.
- Prefer enum methods (e.g., `label()`, `isTerminal()`, `canTransitionTo()`) over scattered `match` statements.
- Keep enum storage consistent. Example: `UserRole` stores values that map to the `users.role` string column.
- Use immutable timestamps in DTOs (`CarbonImmutable`) and serialize dates to ISO-8601 strings in resources.
- Prefer explicit `fillable` or explicit `guarded` on new models (avoid `$guarded = []` unless intentionally chosen).

## Querying and pagination

- For list endpoints, use `Spatie\QueryBuilder\QueryBuilder` and whitelist allowed filters, sorts, and includes.
- Prefer `AllowedFilter::exact()` and named scopes for complex filters.
- Always paginate collections. Never return unbounded lists.
- Avoid N+1 queries; eager load includes and conditional relations.

## Response contract (must match `ApiResponse`)

- Success: `{ success: true, message, data }`
- Error: `{ success: false, message, errors? }`
- All API responses must go through `ApiResponse` helpers (`success()`, `created()`, `noContent()`, `validationError()`, `unauthorized()`, `forbidden()`, `notFound()`).
- Every API response must use a dedicated Resource class in `app/Http/Resources` (no raw model arrays).
- The response envelope is non-negotiable and must match `README.md`.

## API routing and access control

- Shared auth endpoints: `POST /api/v1/register` (user only; admin not registerable), `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/me`.
- Role model: admin vs user is represented by a role column on `users`; `/api/v1/me` must include the role.
- User endpoints must be grouped under `/api/v1/user/**`; admin endpoints under `/api/v1/admin/**`.
- Do not branch response shapes by role within a single endpoint; keep contracts separated by prefix.
- Authorization is mandatory: use `auth:sanctum` plus role checks/policies (RBAC where applicable).

## TypeScript generation (mandatory when DTOs/enums change)

- `resources/types/generated.d.ts` is generated. Do not edit by hand.
- After changing DTOs/enums, run `composer generate` or `composer generate-and-cleanup`.
- After any schema update (migrations, casts, enums, DTO field changes), run `composer generate-and-cleanup`.
- Keep `config/typescript-transformer.php` aligned with new DTOs and enum behavior.

## Quality gates (do before shipping changes)

- `composer cleanup` (runs Pint, Rector, PHPStan, and tests).
- `composer generate-and-cleanup` for auto-fixes.
- Prefer composer scripts for tooling; do not invent ad-hoc command names in docs/CI.
- No style drift: touched files must remain formatter-compliant; refactors must preserve behavior while improving type safety.

## Documentation

- Scramble auto-docs the API. Keep request/response types accurate so docs stay correct.
- New endpoints must be under the `api` path and discoverable by Scramble.
- New endpoints must be visible in the generated OpenAPI output.

## Third-party API integrations (hard rules)

All third-party integrations must be implemented via dedicated service classes with this structure:

```
app/Services/API/{ServiceName}/
  {ServiceName}Service.php
  data/
  enums/
```

- Every external provider is namespaced under `App\Services\API\{ServiceName}\...`.
- All provider DTOs, enums, interfaces, and helper types live inside that provider namespace.
- Provider-specific types must be reused across the system (do not create parallel types elsewhere).
- No controller may call HTTP clients directly.
- No inline integration code inside actions/jobs/models.
- Provider services are the only layer allowed to know provider HTTP details (auth, signing, endpoints).
- Domain actions/services depend on provider services via interfaces where useful.

## Concurrency, idempotency, and ledger safety

- Any balance movement or redemption creation/update must be atomic (DB transactions).
- Endpoints or jobs at risk of double-submit/race conditions must use atomic locking.
- Ledger remains authoritative; cached balances (if present) must reconcile.

## Performance

- Prefer `CarbonImmutable` and immutable value objects in DTOs.
- Use caching/queues for heavy operations; keep controllers I/O focused.

## Testing expectations

- no tests need to be written

## Inspect before modify

- Inspect existing implementation and related modules before writing code.
- Reuse existing DTOs/enums/services where possible and follow established patterns consistently.
