Feature: wp sync push
  Push the local ref to a remote WordPress site.

  Scenario: Push transfers reachable chunks and updates remote ref
    Given site A has committed changes and site B is empty
    When I run `wp sync push --remote=https://b.test --user=alice --password=APP`
    Then STDOUT should contain "pushed"
    And the remote refs/heads/main should equal the local refs/heads/main
    And the remote chunk store should contain every chunk reachable from the commit

  Scenario: Idempotent push transfers zero chunks
    Given site A and site B share the same commit hash for refs/heads/main
    When I run `wp sync push --remote=https://b.test --user=alice --password=APP`
    Then STDOUT should contain "up-to-date"
    And zero chunks should be transferred

  Scenario: Non-fast-forward push is rejected
    Given site A and site B diverge from the same ancestor
    When I run `wp sync push --remote=https://b.test --user=alice --password=APP`
    Then the command should exit with a non-zero status
    And STDERR should mention "non-fast-forward" or "pull first"

  Scenario: Push resumes after interruption without duplicate transfer
    Given a push is interrupted after 50% of chunks
    When I re-run the push
    Then only the missing chunks should be transferred
    And no orphan ref should exist on the remote
