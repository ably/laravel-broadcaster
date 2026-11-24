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
