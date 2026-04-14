Feature: wp sync pull
  Fetch remote chunks and materialize them into the local database.

  Scenario: Pull applies remote changes to live WP DB
    Given site A has committed and pushed a post with URL https://site-a.test/p.jpg
    When on site B I run `wp sync pull --remote=https://a.test --user=bob --password=APP`
    Then wp_posts should contain the post with URL https://site-b.test/p.jpg
    And wp_options.siteurl should remain https://site-b.test
    And _sync_dirty should be empty

  Scenario: Pull is idempotent
    Given site B already has the latest commit from site A
    When I run `wp sync pull --remote=https://a.test --user=bob --password=APP`
    Then zero chunks should be transferred
    And the local working directory should be unchanged
