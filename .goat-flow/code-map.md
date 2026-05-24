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
│   │   ├── SymfonyHttpTransport.php          = Full transport (invoke + SSE), requires symfony/http-client
│   │   └── PsrHttpTransport.php              = PSR-18 transport, invoke only (stream() throws — PSR-18 spec limit)
│   ├── Response/
│   │   ├── AgentResponse.php                 = Invoke response DTO; ::fromArray() defensive hydrator
│   │   ├── GuardrailTrace.php                = Guardrail intervention data
│   │   ├── InterruptDetail.php               = Human-in-the-loop interrupt payload
│   │   ├── StopReason.php                    = Backed enum (EndTurn, ToolUse, MaxTokens, ...)
│   │   └── Usage.php                         = Token usage; ::fromArray() is the single canonical hydrator
│   ├── Streaming/
│   │   ├── StreamEvent.php                   = One parsed event; ::fromArray() throws on unknown, ::tryFromArray() returns null
│   │   ├── StreamEventType.php               = Backed enum (Text, ToolUse, ToolResult, Complete, Error, ...)
│   │   ├── StreamParser.php                  = Incremental SSE parser; 10 MB buffer cap; tolerates unknown event names
│   │   └── StreamResult.php                  = Accumulated stream result + TTFT metric
│   ├── Exceptions/
│   │   ├── StrandsException.php              = Base
│   │   ├── AgentErrorException.php           = HTTP error; carries statusCode + responseBody for structured inspection
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
│   │   └── Integration/                      = Laravel + Symfony DI tests
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
│   └── wire-contract.md                      = Strands HTTP Wire Contract v1 (PHP-facing JSON/SSE shapes)
│
├── scripts/
│   ├── preflight-checks.sh                   = composer preflight runner (used in CI + locally)
│   ├── check-cyclomatic-complexity.php       = Method CC ≤ 20 gate
│   └── setup-initial.sh                      = One-shot dev bootstrap
│
├── .goat-flow/                               = Goat-flow learning loop + skills metadata
│   ├── architecture.md
│   ├── code-map.md                           = This file
│   ├── glossary.md
│   ├── footguns/                             = contract, generated-files, integration, observability, transport, transport-and-streaming
│   ├── lessons/                              = history, verification
│   ├── decisions/                            = ADR-001 wire contract, ADR-002 OTEL response observation
│   ├── patterns/, scratchpad/, tasks/        = Repeatable patterns + ephemeral notes + plan files
│   ├── skill-reference/, skill-playbooks/    = Installed verbatim from goat-flow
│   ├── logs/sessions/                        = Local-only session continuity (gitignored)
│   └── config.yaml                           = goat-flow version pin
│
├── .claude/                                  = Claude-owned harness
│   ├── settings.json                         = Permissions + hook registration
│   ├── settings.local.json                   = Local overrides (gitignored)
│   ├── skills/                               = 7 goat-* skills installed verbatim
│   └── hooks/deny-dangerous.sh               = PreToolUse deny hook (+ self-test)
│
├── .github/
│   ├── workflows/                            = CI pipelines (ci.yml runs preflight)
│   ├── ISSUE_TEMPLATE/, pull_request_template.md
│   ├── git-commit-instructions.md            = Commit guidance (generated stub — needs human review)
│   └── dependabot.yml
│
├── composer.json                             = Dependencies, scripts, branch-alias 1.4.x-dev
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

**Generated/local-only:** `.claude/settings.local.json`, `.goat-flow/logs/sessions/`, `.goat-flow/scratchpad/`, `coverage.xml`, `coverage-html/`.
