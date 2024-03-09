Feature: Test this extension
  Scenario: Test user
    Given as user "test"
    Then user test exists

  Scenario: Test POST with success
    Given sending "POST" to "/"
      | status | 1 |

  Scenario: Test response of POST is numeric
    When set the response to:
      """
      {"status":1}
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key    | value |
      | status | 1     |

  Scenario: Test response of POST is string
    When set the response to:
      """
      {"status":"string"}
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key    | value  |
      | status | string |

  Scenario: Test response of POST is boolean
    When set the response to:
      """
      {"status":true}
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key    | value |
      | status | true  |

  Scenario: Test response of POST is json
    When set the response to:
      """
      {"status":{"string": "test"}}
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key    | value              |
      | status | {"string": "test"} |

  Scenario: Test response of POST is json that match using jq
    When set the response to:
      """
      {
        "Foo": {
          "Bar": "33"
        }
      }
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key | value            |
      | Foo | (jq).Bar == "33" |

  Scenario: Test initial state with string
    Then the response should contain the initial state "appid-string" with the following values:
      """
      default
      """

  Scenario: Test initial state with string
    Then the response should contain the initial state "appid-string" with the following values:
      """
      "text as json string"
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
