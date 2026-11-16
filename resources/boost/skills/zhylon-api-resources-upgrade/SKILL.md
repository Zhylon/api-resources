---
name: zhylon-api-resources-upgrade
description: Upgrade an app to a new major version of zhylon/api-resources (e.g. 1.x to 2.0). Use when the user asks to upgrade, update or migrate zhylon/api-resources, or when composer.json requires an older major version of zhylon/api-resources than the latest one.
---

# Upgrade zhylon/api-resources

Step by step instructions for AI agents to upgrade a Laravel app that uses `zhylon/api-resources`.
The human readable guide is [UPGRADING.md](https://github.com/Zhylon/api-resources/blob/main/UPGRADING.md).

## 1. Find the right guide

1. Read the installed version: `composer show zhylon/api-resources` (or `composer.lock`).
2. Pick the guide for the upgrade. Run them one after another, if the app skips a major version.

| From | To  | Guide                                                |
|------|-----|------------------------------------------------------|
| 1.x  | 2.0 | [references/1.x-to-2.0.md](references/1.x-to-2.0.md) |

If you read this file from GitHub, the guides are in
`https://raw.githubusercontent.com/Zhylon/api-resources/main/resources/boost/skills/zhylon-api-resources-upgrade/references/`.

## 2. Rules for every upgrade

- **The JSON output of the API is a contract with its clients.** Never change the output of existing endpoints (keys, structure, error format, ids) without asking. Each guide marks these steps with **ASK**.
- Breaking changes of the package must be fixed so that the output stays the same. New features are optional: only suggest them, unless the user asks to apply them.
- Only change code that uses this package. Do not refactor unrelated code.
- Do not commit. Leave the changes for the user to review.
- Run the test suite (and static analysis, if the app uses it) after the upgrade and fix failures caused by the upgrade.

## 3. Report

Finish with a short summary for the user:

1. Version before and after.
2. Decisions the user made (or still has to make).
3. Files you changed.
4. Endpoints whose output changed (should be none, unless the user agreed).
5. Things you could not check automatically, e.g. API clients that depend on details of the output.
6. Test results.
