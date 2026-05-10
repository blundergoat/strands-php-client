# Code Map

```
strands-php-client/
├── src/                                    = Production source (PSR-4: StrandsPhpClient\)
│   ├── StrandsClient.php                   = Main client: invoke(), stream(), postJson(), streamSse(), retry logic
│   ├── Auth/                               = Authentication strategies
│   │   ├── AuthStrategy.php                = Interface (single authenticate() method)
│   │   ├── NullAuth.php                    = No-op for local dev (Null Object pattern)
│   │   ├── ApiKeyAuth.php                  = Bearer token / custom header auth
│   │   └── SigV4Auth.php                   = AWS Signature V4 (standalone, no aws-sdk-php)
│   ├── Config/
│   │   └── StrandsConfig.php               = Immutable config: endpoint, timeouts, retries, auth
│   ├── Context/
│   │   ├── AgentContext.php                = Immutable builder: system prompts, metadata, permissions
│   │   └── AgentInput.php                  = Rich input builder: text, images, documents, S3 video
│   ├── Http/                               = Transport abstraction
│   │   ├── HttpTransport.php               = Interface: post() + stream()
│   │   ├── RequestMiddleware.php           = Middleware interface: beforeRequest() + afterResponse()
│   │   ├── SymfonyHttpTransport.php        = Symfony HttpClient (invoke + streaming)
│   │   └── PsrHttpTransport.php            = PSR-18 client (invoke only, stream() throws)
│   ├── Response/
│   │   ├── AgentResponse.php               = Invoke response DTO with fromArray() factory
│   │   ├── GuardrailTrace.php              = Guardrail intervention data
│   │   ├── InterruptDetail.php             = Human-in-the-loop interrupt data
│   │   ├── StopReason.php                  = Backed enum (EndTurn, ToolUse, MaxTokens, etc.)
│   │   └── Usage.php                       = Token usage stats with fromArray() factory
│   ├── Streaming/
│   │   ├── StreamEvent.php                 = Single typed SSE event
│   │   ├── StreamEventType.php             = Backed enum (Text, ToolUse, Complete, Error, etc.)
│   │   ├── StreamParser.php                = Incremental SSE parser with 10 MB buffer limit
│   │   └── StreamResult.php                = Accumulated stream result (text, usage, TTFT, etc.)
│   ├── Exceptions/
│   │   ├── StrandsException.php            = Base exception
│   │   ├── AgentErrorException.php         = HTTP error (carries statusCode + responseBody)
│   │   └── StreamInterruptedException.php  = Stream ended without terminal event
│   └── Integration/
│       ├── StrandsClientFactory.php        = Shared factory (used by both Laravel and Symfony)
│       ├── Laravel/
│       │   ├── StrandsServiceProvider.php  = Service provider with named agent bindings
│       │   ├── Facades/Strands.php         = Facade for default client
│       │   └── config/strands.php          = Publishable config template
│       └── Symfony/
│           ├── StrandsBundle.php           = Bundle registration
│           └── DependencyInjection/
│               ├── Configuration.php       = YAML config schema
│               ├── StrandsExtension.php    = DI container extension
│               └── StrandsClientFactory.php = Symfony-specific factory subclass
│
├── tests/                                  = Unit tests (mocked HTTP, no network)
│   ├── Unit/                               = Mirrors src/ structure
│   │   ├── StrandsClientTest.php           = Core invoke tests
│   │   ├── StrandsClientStreamTest.php     = Stream tests
│   │   ├── StrandsClientPostJsonTest.php   = postJson() tests
│   │   ├── StrandsClientStreamSseTest.php  = streamSse() tests
│   │   ├── StreamParserTest.php            = SSE parsing tests
│   │   ├── SymfonyHttpTransportTest.php    = Symfony transport tests
│   │   ├── PsrHttpTransportTest.php        = PSR-18 transport tests
│   │   ├── ApiKeyAuthTest.php, NullAuthTest.php, SigV4AuthTest.php
│   │   ├── AgentContextTest.php, AgentInputTest.php
│   │   ├── AgentResponseTest.php, GuardrailTraceTest.php, InterruptDetailTest.php
│   │   ├── RequestMiddlewareTest.php
│   │   └── Integration/                    = Framework integration tests
│   │       ├── StrandsClientFactoryTest.php
│   │       ├── Laravel/                    = Laravel service provider tests
│   │       └── Symfony/                    = Symfony bundle DI tests
│   ├── Fixtures/                           = JSON responses and SSE text files for tests
│   ├── Support/StrandsFunctionOverrides.php = Test helper for function mocking
│   └── bootstrap.php                       = PHPUnit bootstrap
│
├── docs/                                   = Documentation
│   ├── usage-guide.md                      = Real-world patterns and examples
│   ├── auth.md                             = Authentication strategies guide
│   ├── rich-input.md                       = AgentInput builder guide
│   ├── interrupts-and-guardrails.md        = Interrupt and guardrail handling
│   ├── laravel-config.md                   = Laravel PHP config reference
│   └── symfony-config.md                   = Symfony YAML config reference
│
├── scripts/
│   ├── preflight-checks.sh                 = Pre-commit quality gate runner
│   ├── check-cyclomatic-complexity.php     = CC checker (max 20 per method)
│   └── setup-initial.sh                    = Initial project setup
│
├── .github/
│   ├── workflows/ci.yml                    = CI pipeline
│   ├── ISSUE_TEMPLATE/                     = Bug report + feature request templates
│   ├── pull_request_template.md            = PR template
│   └── dependabot.yml                      = Dependency update config
│
├── .claude/                                = Claude Code agent config (gitignored)
│   ├── hooks/                              = PreToolUse/PostToolUse/Stop hooks
│   ├── settings.json                       = Agent permissions and hooks
│   └── skills/                             = goat-flow skills
│
├── .goat-flow/                             = goat-flow project knowledge
│   ├── architecture.md, code-map.md, glossary.md
│   ├── footguns/, lessons/, patterns/, decisions/
│   ├── skill-reference/                    = Tool playbooks
│   └── logs/, tasks/, scratchpad/          = Session state
│
├── vendor/                                 = Composer dependencies (gitignored, never edit)
│
├── composer.json                           = Dependencies, scripts, autoload
├── phpunit.xml                             = PHPUnit config
├── phpstan.neon                            = PHPStan Level 10 config
├── phpmd.xml                               = PHPMD rules
├── infection.json5                         = Mutation testing config
├── .php-cs-fixer.php                       = PSR-12 code style config
├── AGENTS.md                               = AI agent guidelines (coding patterns, testing)
├── CHANGELOG.md                            = Version history
├── README.md                               = Project overview and quick start
├── CONTRIBUTING.md                         = Human contributor guidelines
├── SECURITY.md                             = Security policy
├── CODE_OF_CONDUCT.md                      = Code of conduct
└── LICENSE                                 = Apache-2.0
```
