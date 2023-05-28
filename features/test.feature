Feature: Test this extension
  Scenario: Check if all steps of this extension is working fine
    Given as user "test"
    And user test exists
    And sending "POST" to "/"
      | status | 1 |
    And the response should contain the initial state "appid-string" with the following values:
      """
      default
      """
    And the response should contain the initial state "appid-string" with the following values:
      """
      true
      """
    And the response should contain the initial state "appid-string" with the following values:
      """
      null
      """
    And the response should contain the initial state "appid-string" with the following values:
      """
      """
    And the response should contain the initial state "appid-json-object" with the following values:
      """
      {
        "fruit": "orange"
      }
      """
    And the response should contain the initial state "appid-json-array" with the following values:
      """
      [
        "orange"
      ]
      """
