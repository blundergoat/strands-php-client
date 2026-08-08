# Code Map — strands-php-client

Repository layout. Every path listed exists on disk.

```
strands-php-client/
├── src/                                      = Production code (PSR-4: StrandsPhpClient\)
│   ├── StrandsClient.php                     = Public entry point — invoke / stream / postJson / streamSse + retry
│   ├── Auth/
│   │   ├── AuthStrategy.php                  = Interface — single authenticate() method; runs AFTER middleware
│   │   ├── NullAuth.php                      = Null Object (local dev)
│   │   ├── ApiKeyAuth.php                    = Bearer / custom header
│   │   └── SigV4Auth.php                     = AWS Signature V4 (standalone, no aws-sdk-php); ::fromEnvironment() helper
│   ├── Config/
│   │   └── StrandsConfig.php                 = Immutable config: endpoint, auth, timeouts, retries
│   ├── Context/
│   │   ├── AgentContext.php                  = Immutable builder (system prompt, metadata, permissions, documents)
│   │   └── AgentInput.php                    = Rich input (text + images + documents + interrupt-response)
│   ├── Http/
│   │   ├── HttpTransport.php                 = Interface — post() + stream(); no defaults (interface)
│   │   ├── RequestMiddleware.php             = Middleware interface — beforeRequest() + afterResponse()
│   │   ├── ResponseObserver.php              = Parsed response/stream observer interface
│   │   ├── ResponseObserverNotifier.php      = Observer normalization, deduplication, and callback dispatch
│   │   ├── Middleware/
│   │   │   └── OtelTracingMiddleware.php     = OpenTelemetry request middleware + response observer
│   │   ├── SymfonyHttpTransport.php          = Full transport (invoke + SSE), requires symfony/http-client
│   │   └── PsrHttpTransport.php              = PSR-18 transport, invoke only (stream() throws — PSR-18 spec limit)
│   ├── Response/
│   │   ├── AgentResponse.php                 = Invoke response DTO; ::fromArray() defensive hydrator
│   │   ├── Message.php                       = Normalized message content DTO
│   │   ├── MessageMetadata.php               = Model/session metadata DTO
│   │   ├── GuardrailTrace.php                = Guardrail intervention data
│   │   ├── GuardrailAssessment.php           = Flattened guardrail assessment record
│   │   ├── InterruptDetail.php               = Human-in-the-loop interrupt payload
│   │   ├── StopReason.php                    = Backed enum (EndTurn, ToolUse, MaxTokens, ...)
│   │   ├── Usage.php                         = Token usage; ::fromArray() is the single canonical hydrator
│   │   └── Citation/                         = Citation source/location/generated-content DTOs
│   ├── Streaming/
│   │   ├── StreamEvent.php                   = One parsed event; ::fromArray() throws on unknown, ::tryFromArray() returns null
│   │   ├── StreamEventType.php               = Backed enum (Text, ToolUse, ToolResult, Complete, Error, ...)
│   │   ├── StreamCallbackHandler.php         = Callback adapter for typed stream events
│   │   ├── PrintingCallbackHandler.php       = Convenience callback handler for printing text events
│   │   ├── StreamParser.php                  = Incremental SSE parser; 10 MB buffer cap; tolerates unknown event names
│   │   ├── StreamResult.php                  = Accumulated stream result + TTFT metric
│   │   └── StreamSseSummary.php              = Sanitized raw SSE custom-endpoint summary
│   ├── Exceptions/
│   │   ├── StrandsException.php              = Base
│   │   ├── AgentErrorException.php           = HTTP error; carries statusCode + responseBody for structured inspection
│   │   ├── ContextOverflowException.php      = Agent context-window overflow error
│   │   ├── MaxTokensException.php            = Agent max-token stop/error condition
│   │   ├── ThrottledException.php            = Retryable throttling error
│   │   └── StreamInterruptedException.php    = Stream ended without a terminal frame
│   └── Integration/                          = Framework wiring
│       ├── StrandsClientFactory.php          = Shared factory (Laravel + Symfony reuse)
│       ├── Laravel/
│       │   ├── StrandsServiceProvider.php    = Service provider with named agent bindings (register + boot)
│       │   ├── Facades/Strands.php           = Default-client facade
│       │   └── config/strands.php            = Publishable Laravel config template
│       └── Symfony/
│           ├── StrandsBundle.php             = Bundle registration
│           └── DependencyInjection/
│               ├── Configuration.php         = YAML config schema
│               ├── StrandsExtension.php      = DI container extension
│               └── StrandsClientFactory.php  = Symfony-specific subclass of shared factory
│
├── tests/                                    = PHPUnit suite (psr-4 dev: StrandsPhpClient\Tests\)
│   ├── Unit/                                 = Mirrors src/; all HTTP mocked, no network/Docker
│   │   ├── StrandsClientTest.php
│   │   ├── StrandsClientStreamTest.php
│   │   ├── StrandsClientPostJsonTest.php
│   │   ├── StrandsClientStreamSseTest.php
│   │   ├── StreamParserTest.php
│   │   ├── SymfonyHttpTransportTest.php
│   │   ├── PsrHttpTransportTest.php
│   │   ├── AgentContextTest.php
│   │   ├── AgentInputTest.php
│   │   ├── AgentResponseTest.php
│   │   ├── GuardrailTraceTest.php
│   │   ├── InterruptDetailTest.php
│   │   ├── NullAuthTest.php
│   │   ├── ApiKeyAuthTest.php
│   │   ├── SigV4AuthTest.php
│   │   ├── RequestMiddlewareTest.php
│   │   ├── ResponseObserverTest.php
│   │   ├── Contract/                         = Wire-contract fixture and consumer-compatibility tests
│   │   ├── Exceptions/                       = Exception DTO/error tests
│   │   ├── Response/                         = Guardrail/citation DTO tests
│   │   ├── Streaming/                        = Stream callback/citation event tests
│   │   └── Integration/                      = Laravel + Symfony DI tests
│   ├── Http/
│   │   └── Middleware/                       = OpenTelemetry middleware and PHI-safety tests
│   ├── Fixtures/                             = Captured JSON + SSE bodies
│   │   └── wire-contract/                    = Canonical v1 wire-contract fixtures
│   ├── Support/                              = Test helpers (mock transports, fixture loaders)
│   └── bootstrap.php                         = Composer autoloader bootstrap
│
├── docs/                                     = Long-form user docs
│   ├── usage-guide.md
│   ├── auth.md
│   ├── rich-input.md
│   ├── interrupts-and-guardrails.md
│   ├── laravel-config.md
│   ├── symfony-config.md
│   ├── wire-contract.md                      = Strands HTTP Wire Contract v1 (PHP-facing JSON/SSE shapes)
│   ├── wire-contract-audit.md                = Wrapper audit notes against the wire contract
│   ├── wire-contract-consumer-matrix.md      = Consumer compatibility matrix
│   └── coding-standards/git-commit-message.md = Commit guidance
│
├── scripts/
│   ├── preflight-checks.sh                   = composer preflight runner (used in CI + locally)
│   ├── check-cyclomatic-complexity.php       = Method CC ≤ 20 gate
│   ├── setup-initial.sh                      = One-shot dev bootstrap
│   ├── dependencies-install.sh               = Dependency install helper
│   ├── dependencies-update.sh                = Dependency update helper
│   └── bump-version.sh                       = Release version bump helper
│
├── .goat-flow/                               = Goat-flow learning loop + skills metadata
│   ├── architecture.md
│   ├── code-map.md                           = This file
│   ├── glossary.md
│   ├── learning-loop/                        = Footguns, lessons, patterns, decisions + generated indexes
│   │   ├── footguns/                         = ci, contract, generated-files, gruff-php, hooks, integration, learning-loop, observability, transport-and-streaming
│   │   ├── lessons/                          = gruff-php, gruff-test-quality, history, review, verification
│   │   ├── decisions/                        = ADR-001 wire contract, ADR-002 OTEL response observation
│   │   └── patterns/                         = Repeatable implementation/testing/release patterns
│   ├── plans/                                = Local session plan files (gitignored by design)
│   ├── scratchpad/                           = Local scratch notes (gitignored)
│   ├── skill-docs/                           = Installed verbatim from goat-flow
│   │   └── playbooks/                        = browser-use.md, changelog.md, code-comments.md, gruff-code-quality.md, hook-policy-testing.md, observability.md, page-capture.md, release-notes.md, skill-playbook-authoring-sync.md, writing-style.md
│   ├── logs/sessions/                        = Local-only session continuity (gitignored)
│   ├── hooks/                                = Shared goat-flow hook scripts and policy
│   └── config.yaml                           = goat-flow version pin
│
├── .claude/                                  = Claude-owned harness
│   ├── settings.json                         = Permissions + hook registration
│   ├── settings.local.json                   = Local overrides (gitignored)
│   └── skills/                               = 7 goat-* skills installed verbatim
│
├── .agents/                                  = Codex goat-flow skills
│   └── skills/                               = 7 goat-* skills installed verbatim
│
├── .codex/                                   = Codex-owned harness
│   ├── config.toml                           = Permission profile + hooks feature flag
│   └── hooks.json                            = Hook registrations pointing to .goat-flow/hooks/
│
├── .github/                                  = GitHub CI, issue templates, and Copilot harness surfaces
│   ├── workflows/                            = CI pipelines
│   ├── hooks/                                = Copilot hook registration
│   ├── skills/                               = 7 goat-* skills installed for Copilot
│   ├── copilot-instructions.md               = Copilot instruction file
│   ├── ISSUE_TEMPLATE/, pull_request_template.md
│   ├── git-commit-instructions.md            = Copilot commit guidance pointing to the project standard
│   └── dependabot.yml
│
├── node_modules/@blundergoat/goat-flow/      = Installed goat-flow package (never edit)
│   └── dist/dashboard/views/                 = HTML view files (about, home, hooks, plans, projects, prompts, quality, settings, setup, skills, workspace)
│
├── composer.json                             = Dependencies, scripts, branch-alias 1.5.x-dev
├── composer.lock                             = Pinned dev deps
├── phpunit.xml                               = Test runner config
├── phpstan.neon                              = Static analysis (Level 10)
├── phpmd.xml                                 = Mess detector rules
├── infection.json5                           = Mutation testing config
├── .php-cs-fixer.php                         = PHP-CS-Fixer rule set (PSR-12)
├── AGENTS.md                                 = Codex peer instruction file
├── CLAUDE.md                                 = Claude instruction file
├── README.md, CHANGELOG.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md, LICENSE
│
├── vendor/                                   = Composer install output — never edit
├── node_modules/                             = Goat-flow distribution — never edit
└── coverage.xml                              = Coverage report (gitignored, generated)
```

**Never edit:** `vendor/`, `node_modules/`, `coverage.xml`, `.goat-flow/audit-cache.json`, `.goat-flow/dashboard-state.json`.

**Generated/local-only:** `.claude/settings.local.json`, `.goat-flow/logs/sessions/`, `.goat-flow/plans/`, `.goat-flow/scratchpad/`, `coverage.xml`, `coverage-html/`.
