Feature: Assertion failures are reported by the Behat runner

  Scenario: Incorrect HTTP response status is rejected
    When sending "GET" to "/"
    Then the response should have a status code "418"

  Scenario: Incorrect JSON array order is rejected
    When set the response to:
      """
      {"payload":[1,2]}
      """
    And sending "GET" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key     | value |
      | payload | [2,1] |

  Scenario: A missing required step argument is rejected
    Given as user
