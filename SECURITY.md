# Security

## Reporting a vulnerability

Please do not open a public issue for a security problem. Use GitHub's
private vulnerability reporting on this repository ("Security" tab,
"Report a vulnerability"), which reaches the maintainer directly. You
should get an acknowledgement within a week.

## Scope

The engine runs AI-generated text through Twig autoescaping, holds the
"Ask the data" feature to a read-only database user behind a token-level
SQL validator, and never stores reader identifiers in its logs. Reports
about any of these guarantees, about the MCP server's read-only surface, or
about the ingest fetcher (robots.txt, User-Agent, SSRF) are especially
welcome.

Provider API keys, database credentials and the sites' own configuration
are not part of this repository. If you believe a secret has leaked through
it, report that the same way.
