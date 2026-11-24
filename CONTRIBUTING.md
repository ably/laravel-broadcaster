# Contributing to ably/laravel-broadcaster

This guide is for maintainers. To propose a change, follow [Contributing](README.md#contributing) in the README.

## Release Process
This library uses [semantic versioning](http://semver.org/). For each release, the following needs to be done:

1. Create a new branch for the release, named like `release/1.1.0` (where `1.1.0` is what you're releasing, being the new version). Critical fixes for 1.0.x go to the `1.0.x` branch.
2. Update the lib version in `src/AblyBroadcaster.php`.
3. Run [`github_changelog_generator`](https://github.com/github-changelog-generator/github-changelog-generator) to automate the update of the [CHANGELOG.md](CHANGELOG.md). This may require some manual intervention, both in terms of how the command is run and how the change log file is modified. Your mileage may vary:
  - The command you will need to run will look something like this: `github_changelog_generator -u ably -p laravel-broadcaster --since-tag v1.0.6 --output delta.md --token $GITHUB_TOKEN_WITH_REPO_ACCESS`. Generate token [here](https://github.com/settings/tokens/new?description=GitHub%20Changelog%20Generator%20token).
  - Using the command above, `--output delta.md` writes changes made after `--since-tag` to a new file.
  - The contents of that new file (`delta.md`) then need to be manually inserted at the top of the `CHANGELOG.md`, changing the "Unreleased" heading and linking with the current version numbers.
  - Also ensure that the "Full Changelog" link points to the new version tag instead of the `HEAD`.
4. Commit generated [CHANGELOG.md](./CHANGELOG.md) file.
5. Make a PR against `main`.
6. Once the PR is approved, merge it into `main`.
7. Add a tag and push it to origin - e.g.: `git tag v1.0.6 && git push origin v1.0.6`. For 1.1, publish `@ably/laravel-echo` 1.1 first (or together with this release).
8. Visit https://github.com/ably/laravel-broadcaster/tags and add release notes for the release including links to the changelog entry.
9. Visit https://packagist.org/packages/ably/laravel-broadcaster, log in to Packagist, and click the "Update" button.
10. Create the entry on the [Ably Changelog](https://changelog.ably.com/) (via [headwayapp](https://headwayapp.co/))

### Maintenance releases (1.x and 1.0.x)

Use this once 2.0.0 is released from `main` and older lines still get fixes:
- 1.1.x patches (`v1.1.1`, `v1.1.2`, ...) are released from the `1.x` branch.
- 1.0.x critical fixes (`v1.0.9`, ...) are released from the `1.0.x` branch, until about October 2027.

Packagist reads tags from every branch, so a `v1.1.1` tag on `1.x` is a normal release. Composer users on `^1.1` get it, and never 2.0. On Packagist it is listed below 2.0.0, because versions are sorted by number.

For a release from a maintenance branch, follow the steps in this section instead of steps 1 to 9 above.

Below, `<branch>` is `1.x` or `1.0.x`, `<version>` is the version being released (e.g. `1.1.1`), `<previous-version>` is the last release on that branch (e.g. `1.1.0`), and the git tag is `v<version>` as usual.

#### One-time setup

1. CI: `.github/workflows/check.yml` must list `'*.x'` under `on.push.branches`, so pushes to maintenance branches run the checks. A branch keeps the workflow file it was cut with, so a branch cut before that change needs it as its first commit.
2. Cut the `1.x` branch before the first 2.0 commit lands on `main`:
    ```shell
    git fetch origin
    git log --oneline v1.1.0..origin/main   # must list only 1.x-safe commits (docs, CI)
    git switch -c 1.x origin/main
    git push -u origin 1.x
    ```
    If 2.0 commits are already on `main`, cut it from the tag instead (`git switch -c 1.x v1.1.0`, then `git push -u origin 1.x`), and bring in the `'*.x'` CI commit as its first PR, as in [Backport a fix](#backport-a-fix).
3. Cut the `1.0.x` branch only when the first 1.0.x critical fix is needed: `git switch -c 1.0.x v1.0.8`, then `git push -u origin 1.0.x`. Its `check.yml` comes from `v1.0.8`, so in its first PR:
    - add `'*.x'` to `on.push.branches`;
    - replace the `phplint` job with the one from `v1.1.0` (the old `aglipanci/laravel-pint-action` no longer works);
    - run the matrix on that PR and drop any legs (for example PHP 7.x, or Laravel 6 or 7) that fail to install on `ubuntu-latest`.
4. Packagist: the package is not auto-updated today. Ask a repository admin to enable the Packagist GitHub integration (https://packagist.org/about#how-to-update-packages). Until then, click "Update" on https://packagist.org/packages/ably/laravel-broadcaster after every tag, or the new version can take up to a week to appear.
5. Do not add a `version` field or an `extra.branch-alias` to `composer.json`. Packagist reads versions from tags, and lists a branch named `1.x` as `1.x-dev`.
6. Optional: ask a repository admin to protect `*.x` branches (pull requests only, required checks).

#### Backport a fix

1. Fix it on `main` first with a normal PR. Only a bug that exists in the older line alone is fixed directly on a branch from `origin/<branch>`.
2. Cherry-pick it into a PR against the maintenance branch. `-x` adds "(cherry picked from commit ...)" to the commit message.
    ```shell
    git fetch origin
    git switch -c backport/<topic>-<branch> origin/<branch>
    git cherry-pick -x <commit-sha-on-main>   # add -m 1 if the commit is a merge commit
    git push -u origin backport/<topic>-<branch>
    gh pr create -R ably/laravel-broadcaster --base <branch> --title "[<branch>] <fix summary>" \
      --body "Backport of <main-pr-url>"
    ```
3. Backports go from `main` to the maintenance branches only. Never merge or cherry-pick maintenance release commits (version bump, changelog) into `main`.

#### Release a maintenance version

1. Open the release PR against the maintenance branch:
    ```shell
    git fetch origin
    git switch -c release/<version> origin/<branch>
    ```
    - Set `const LIB_VERSION = '<version>';` in `src/AblyBroadcaster.php`.
    - Add the entry at the top of [CHANGELOG.md](CHANGELOG.md) on this branch. Write it by hand, or generate it as above with `--since-tag v<previous-version>` and check the output:
    ```markdown
    ## [v<version>](https://github.com/ably/laravel-broadcaster/tree/v<version>)

    [Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v<previous-version>...v<version>)
    ```
    ```shell
    git commit -am "Prepare <version> release"
    git push -u origin release/<version>
    gh pr create -R ably/laravel-broadcaster --base <branch> --title "Release <version>" --body "Release <version>"
    ```
2. Once the PR is approved and merged, tag the merge commit by its SHA and push the tag. Keep the `v` prefix. If the release needs a new `@ably/laravel-echo` 1.x, publish that first.
    ```shell
    SHA=$(gh pr view <pr-number> -R ably/laravel-broadcaster --json mergeCommit -q .mergeCommit.oid)
    git fetch origin
    git merge-base --is-ancestor "$SHA" origin/<branch> && echo "on <branch>"   # must print "on <branch>"
    git tag v<version> "$SHA"
    git push origin v<version>
    ```
3. Publish on Packagist: log in and click "Update" on https://packagist.org/packages/ably/laravel-broadcaster. Once the GitHub integration is enabled, the tag push is enough.
4. Create the GitHub release without taking "Latest" from 2.x. In the web UI, untick "Set as latest release".
    ```shell
    gh release create v<version> -R ably/laravel-broadcaster --verify-tag --latest=false \
      --title v<version> --notes-file <notes-file>   # <notes-file>: the CHANGELOG.md entry for <version>
    ```
    Then create the entry on the [Ably Changelog](https://changelog.ably.com/) as usual.
5. Verify (Packagist's metadata can lag a few minutes):
    ```shell
    curl -s https://repo.packagist.org/p2/ably/laravel-broadcaster.json \
      | python3 -c "import json,sys; print([v['version'] for v in json.load(sys.stdin)['packages']['ably/laravel-broadcaster']][:5])"
    # e.g. ['v2.0.0', 'v1.1.1', 'v1.1.0', ...]
    gh release list -R ably/laravel-broadcaster -L 3   # "Latest" is still the 2.x release
    ```
6. Optional: copy the changelog entry into `CHANGELOG.md` on `main`, below the 2.x entries.

#### Pitfalls

- Without `--latest=false`, `gh release create` makes the older release "Latest". Without `--verify-tag`, a missing tag is created from `main`.
- Never move or re-push a published tag. Packagist keeps the commit it first saw for a stable version. Release the next patch instead.
- If the new version does not appear on Packagist, click "Update", and check that `composer validate` passes at the tag.
- Forgetting `LIB_VERSION`: the broadcaster reports it to Ably in its agent header, so the release would report the wrong version.
- Do not mark the package as abandoned on Packagist while 1.x is supported. The flag applies to the whole package, 2.x included.

#### Staying on 1.x (for users)

- `composer require ably/laravel-broadcaster:^1.1` stays on 1.1.x and later 1.x releases. `1.0.*` or `~1.0.8` stays on 1.0.x.
- `composer outdated` then lists 2.0 as a major update (yellow `~`), which is expected.
