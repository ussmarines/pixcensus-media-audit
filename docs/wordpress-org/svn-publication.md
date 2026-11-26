# Publishing an update

Merge the validated change into main. Push the version tag on that commit to start the Release workflow. It builds and tests the installable ZIP, attests the artifact and copies its contents to SVN trunk and the matching version tag. The two PNG screenshots are published to SVN assets. After the SVN step, the workflow publishes the GitHub release with the tested ZIP and checksum. Verify the public WordPress.org version, download and listing after indexing. If WordPress.org requests release confirmation, a project committer must complete it.

## Compatibility and presentation updates

For readme-only publication, keep the existing plugin version and immutable release. Merge the reviewed branch through a PR, wait for required checks, then dispatch `WordPress.org readme update` on `main` with the exact reviewed main SHA in `expected_sha`. It validates the current plugin/stable version and updates only `trunk/readme.txt` and `tags/<stable-version>/readme.txt`. The stored SVN secret is injected into the commit process via stdin and is never read locally.

After completion, verify both public SVN readmes, the listing `tested` value and stable version, and the downloaded ZIP. The WordPress.org ZIP may have a newer readme than the immutable GitHub release; compare all other entries to establish unchanged runtime. A successful SVN commit alone does not prove directory indexing has finished.
