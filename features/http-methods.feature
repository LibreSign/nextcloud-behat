Feature: HTTP method arguments are case-insensitive
  Scenario Outline: Send normalized HTTP method from steps
    When sending "<method>" to "/"
    Then the response should have a status code "200"

    Examples:
      | method |
      | get    |
      | GET    |
      | post   |
      | PoSt   |
      | patch  |
      | delete |

  Scenario: OCS steps also normalize lowercase method
    When sending "post" to ocs "/"
    Then the response should have a status code "200"
