# Publishing an update

Merge the validated change into main. Push the version tag on that commit to start the Release workflow. It builds and tests the installable ZIP, attests the artifact and copies its contents to SVN trunk and the matching version tag. The two PNG screenshots are published to SVN assets. After the SVN step, the workflow publishes the GitHub release with the tested ZIP and checksum. Verify the public WordPress.org version, download and listing after indexing. If WordPress.org requests release confirmation, a project committer must complete it.
