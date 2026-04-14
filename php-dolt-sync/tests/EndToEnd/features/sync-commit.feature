Feature: wp sync commit
  Create a content-addressed commit of the live WordPress SQLite database.

  Scenario: First commit with no parent
    Given a fresh WordPress site using SQLite
    And a post titled "Hello world" exists
    When I run `wp sync commit --message="initial"`
    Then STDOUT should contain "committed:"
    And the chunk database should contain at least one chunk
    And refs/heads/main should point to the new commit hash

  Scenario: Incremental commit reuses unchanged table trees
    Given an existing commit C1 on main
    And dirty-tracking triggers are installed
    And I insert one row into wp_postmeta
    When I run `wp sync commit --message="add meta"`
    Then the wp_posts table tree hash should equal the one recorded in C1
    And the wp_postmeta table tree hash should differ

  Scenario: Empty commit (no dirty rows) reuses prior db root
    Given an existing commit C1 on main
    When I run `wp sync commit --message="nothing"`
    Then the new commit's root hash should equal C1's root hash
    And the new commit's hash should differ from C1 (timestamp differs)
