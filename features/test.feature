Feature: Test this extension
  Scenario: Test user
    Given as user "test"
    Then user test exists

  Scenario: Test POST with success
    Given sending "POST" to "/"
      | status | 1 |

  Scenario: Test response of POST is numeric
    When sending "POST" to "/"
      | status | 1 |
    Then the response should be a JSON array with the following mandatory values
      | status | 1 |

  Scenario: Test response of POST is string
    When sending "POST" to "/"
      | status | "string" |
    Then the response should be a JSON array with the following mandatory values
      | status | "string" |

  Scenario: Test response of POST is boolean
    When sending "POST" to "/"
      | status | true |
    Then the response should be a JSON array with the following mandatory values
      | status | true |

  Scenario: Test response of POST is json
    When sending "POST" to "/"
      | status | (string){"string": "test"} |
    Then the response should be a JSON array with the following mandatory values
      | status | {"string": "test"} |

  Scenario: Test initial state with string
    Then the response should contain the initial state "appid-string" with the following values:
      """
      default
      """

  Scenario: Test initial state with boolean
    Then the response should contain the initial state "appid-string" with the following values:
      """
      true
      """

  Scenario: Test initial state with null
    Then the response should contain the initial state "appid-string" with the following values:
      """
      null
      """

  Scenario: Test initial state with empty
    Then the response should contain the initial state "appid-string" with the following values:
      """
      """

  Scenario: Test initial state with json
    Then the response should contain the initial state "appid-json-object" with the following values:
      """
      {
        "fruit": "orange"
      }
      """

  Scenario: Test initial state with array
    Then the response should contain the initial state "appid-json-array" with the following values:
      """
      [
        "orange"
      ]
      """
