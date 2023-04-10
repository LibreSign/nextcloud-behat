Feature: Test this extension
  Scenario: Check if all steps of this extension is working fine
    Given as user "test"
    And user test exists
    And sending "POST" to "/"
      | status | 1 |
