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
  - Once 1.x or 1.0.x releases exist, add `--exclude-tags-regex '^v1\.'` (with a 2.x `--since-tag`), so that 1.x and 1.0.x releases newer by date are not listed. See [Maintenance releases](#maintenance-releases-1x-and-10x).
4. Commit generated [CHANGELOG.md](./CHANGELOG.md) file.
5. Make a PR against `main`.
6. Once the PR is approved, merge it into `main`.
7. Add a tag and push it to origin - e.g.: `git tag v1.0.6 && git push origin v1.0.6`. For 1.1, publish `@ably/laravel-echo` 1.1 first (or together with this release).
8. Visit https://github.com/ably/laravel-broadcaster/tags and add release notes for the release including links to the changelog entry.
9. Visit https://packagist.org/packages/ably/laravel-broadcaster, log in to Packagist, and click the "Update" button.
10. Create the entry on the [Ably Changelog](https://changelog.ably.com/) (via [headwayapp](https://headwayapp.co/))

### Maintenance releases (1.x and 1.0.x)

Use this once 2.0.0 is released from `main` and an older line needs a fix:
- 1.1.x patches (`v1.1.1`, `v1.1.2`, ...) are released from the `1.x` branch. 1.1.x requires `ably/pubsub-server` ^2.0 and PHP 8.1 or later.
- 1.0.x critical fixes (`v1.0.9`, ...) are released from the `1.0.x` branch, until about October 2027. 1.0.x requires `ably/ably-php` ^1.1, and supports PHP 7.2 and later and Laravel 6 and later.

Each branch is cut when its line needs its first fix, as in [Cut a maintenance branch](#cut-a-maintenance-branch). Later fixes and releases for that line reuse it.

Packagist reads tags from every branch, so a `v1.1.1` tag on `1.x` is a normal release. Composer users on `^1.1` get it, and never 2.0. On Packagist it is listed below 2.0.0, because versions are sorted by number.

For a release from a maintenance branch, follow the steps in this section instead of steps 1 to 9 above.

Below, `<branch>` is `1.x` or `1.0.x`, `<version>` is the version being released (e.g. `1.1.1`), `<previous-version>` is the last release on that branch (e.g. `1.1.0`), and the git tag is `v<version>` as usual.

#### One-time setup

1. Packagist: the package is not auto-updated today. Ask a repository admin to enable the Packagist GitHub integration (https://packagist.org/about#how-to-update-packages). Until then, click "Update" on https://packagist.org/packages/ably/laravel-broadcaster after every tag, or the new version can take up to a week to appear.
2. Do not add a `version` field or an `extra.branch-alias` to `composer.json`. Packagist reads versions from tags, and lists a branch named `1.x` as `1.x-dev`.
3. Optional: ask a repository admin to protect `*.x` branches (pull requests only, required checks).

#### Cut a maintenance branch

Do this when a line needs its first fix and `git ls-remote --heads origin <branch>` lists nothing.

1. Pick the base. Use the line's last release tag, or a later commit on `main` only if nothing after the tag is work for the next line:
    - `1.x`: the last commit on `main` before the 2.0 work, so the branch has the `'*.x'` CI trigger. If that is unclear, use the last 1.1.x tag.
    - `1.0.x`: the last commit on `main` before the 1.1 work. That is `83218af`, the CI hardening merge right after `v1.0.8`. If that is unclear, use the last 1.0.x tag.
2. Find and check the base:
    ```shell
    git fetch origin
    git log --oneline --first-parent --reverse <last-tag>..origin/main   # <base>: the last commit before the next line's work
    git diff --stat <last-tag> <base> -- src composer.json                 # must print nothing
    ```
3. Create the branch:
    ```shell
    git switch -c <branch> <base>
    git push -u origin <branch>
    ```
4. Fix CI in the branch's first PR. A branch keeps the workflow file it was cut from, so check `.github/workflows/check.yml` on it:
    - add `'*.x'` to `on.push.branches` if it is missing (it is missing at every tag up to `v1.1.0`, and at `83218af`);
    - on `1.0.x`, replace the `phplint` job with the one from `v1.1.0` (the old `aglipanci/laravel-pint-action` no longer works);
    - on `1.0.x`, run the matrix on that PR and drop any legs (for example PHP 7.x, or Laravel 6 or 7) that fail to install on `ubuntu-latest`.

    No workflow depends on the repository name or a cloud role, so nothing else changes.
5. Make the fix and release it, as in [Backport a fix](#backport-a-fix) and [Release a maintenance version](#release-a-maintenance-version).

#### Backport a fix

1. Fix it on `main` first with a normal PR. Only a bug that exists in the older line alone is fixed directly on a branch from `origin/<branch>`.
2. If the maintenance branch does not exist yet, [cut it](#cut-a-maintenance-branch).
3. Cherry-pick it into a PR against the maintenance branch. `-x` adds "(cherry picked from commit ...)" to the commit message.
    ```shell
    git fetch origin
    git switch -c backport/<topic>-<branch> origin/<branch>
    git cherry-pick -x <commit-sha-on-main>   # add -m 1 if the commit is a merge commit
    git push -u origin backport/<topic>-<branch>
    gh pr create -R ably/laravel-broadcaster --base <branch> --title "[<branch>] <fix summary>" \
      --body "Backport of <main-pr-url>"
    ```
4. On `1.0.x`, a fix from `main` usually does not cherry-pick cleanly. Port it by hand in the same kind of PR:
    - 1.0.x uses `ably/ably-php` 1.x, whose classes are `Ably\…`, not `Ably\PubSub\…`;
    - 1.0.x supports PHP 7.2, so the port must not use newer syntax.
5. Backports go from `main` to the maintenance branches only. Never merge or cherry-pick maintenance release commits (version bump, changelog) into `main`.

#### Release a maintenance version

1. Open the release PR against the maintenance branch:
    ```shell
    git fetch origin
    git switch -c release/<version> origin/<branch>
    ```
    - Set `const LIB_VERSION = '<version>';` in `src/AblyBroadcaster.php`.
    - Add the entry at the top of [CHANGELOG.md](CHANGELOG.md) on this branch. Write it by hand, or generate it with this line's command and check the output:
        ```shell
        # 1.x
        github_changelog_generator -u ably -p laravel-broadcaster --since-tag v<previous-version> --release-branch 1.x \
          --exclude-tags-regex '^v([2-9]|1\.0)\.' --output delta.md --token $GITHUB_TOKEN_WITH_REPO_ACCESS
        # 1.0.x
        github_changelog_generator -u ably -p laravel-broadcaster --since-tag v<previous-version> --release-branch 1.0.x \
          --exclude-tags-regex '^v([2-9]|1\.[1-9])\.' --output delta.md --token $GITHUB_TOKEN_WITH_REPO_ACCESS
        ```
        - `--release-branch` makes the generator look for untagged pull requests on this branch. Without it, it looks on `main` and drops the rest.
        - `--exclude-tags-regex` leaves out the other lines' tags. `--since-tag` alone keeps every tag that is newer by date, so a 2.x release would show up as a section.
        - Delete any closed 2.x issues and any docs- or CI-only pull requests that the output still lists, and delete `delta.md` rather than committing it.
    ```markdown
    ## [v<version>](https://github.com/ably/laravel-broadcaster/tree/v<version>)

    [Full Changelog](https://github.com/ably/laravel-broadcaster/compare/v<previous-version>...v<version>)
    ```
    ```shell
    git commit -am "Prepare <version> release"
    git push -u origin release/<version>
    gh pr create -R ably/laravel-broadcaster --base <branch> --title "Release <version>" --body "Release <version>"
    ```
2. Once the PR is approved and merged, tag the merge commit and push the tag. Keep the `v` prefix. If the release needs a new `@ably/laravel-echo` 1.x, publish that first.
    ```shell
    git fetch origin
    git switch <branch>                   # the first time, this creates <branch> tracking origin/<branch>
    git pull --ff-only origin <branch>
    git log -1 --oneline                  # must be "Merge pull request ... from ably/release/<version>"
    ```
    If the last line shows any other commit, stop. Otherwise tag it:
    ```shell
    git tag v<version>
    git push origin v<version>
    ```
3. Publish on Packagist: log in and click "Update" on https://packagist.org/packages/ably/laravel-broadcaster. Once the GitHub integration is enabled, the tag push is enough.
4. Create the GitHub release without taking "Latest" from 2.x. In the web UI, untick "Set as latest release".
    ```shell
    gh release create v<version> -R ably/laravel-broadcaster --verify-tag --latest=false \
      --title v<version> --notes-file <notes-file>
    ```
    `<notes-file>` holds the `CHANGELOG.md` entry for `<version>` without its `##` heading. Make relative links such as `UPDATING.md#...` absolute: they don't resolve on the release page.
    Then create the entry on the [Ably Changelog](https://changelog.ably.com/) as usual.
5. Verify (Packagist's metadata can lag a few minutes):
    ```shell
    curl -s https://repo.packagist.org/p2/ably/laravel-broadcaster.json \
      | python3 -c "import json,sys; vs=[v['version'] for v in json.load(sys.stdin)['packages']['ably/laravel-broadcaster']]; print('published' if 'v<version>' in vs else 'not listed yet')"
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
