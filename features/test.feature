Feature: Test this extension
  Scenario: Test user
    Given as user "test"
    Then user test exists

  Scenario: Test POST with success
    Given sending "POST" to "/"
      | status | 1 |

  Scenario: Test POST with success
    Given set the custom http header "GUSTOM_HEADER" with "custom-value" as value to next request
    Then sending "POST" to "/"
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
          "Bar": "33",
          "Foo": false
        }
      }
      """
    And sending "POST" to "/"
    Then the response should be a JSON array with the following mandatory values
      | key          | value                    |
      | Foo          | (jq).Bar == "33"         |
      | (jq).Foo     | {"Bar":"33","Foo":false} |
      | (jq).Foo     | (jq).Bar == "33"         |
      | (jq).Foo.Bar | 33                       |
      | (jq).Foo.Foo | false                    |

  Scenario: Test get field from json response
    When set the response to:
      """
      {
        "data": [
          {
            "foo":"bar"
          }
        ]
      }
      """
    And sending "POST" to "/"
    And fetch field "(FIELD_FOO)data.0.foo" from previous JSON response
    # After fetch the field, you can use the value of field like this:
    And sending "POST" to "/?foo=<FIELD_FOO>"
      | field | <data.0.foo> |
    Then the response should be a JSON array with the following mandatory values
      | key  | value             |
      | data | [{"foo":"<FIELD_FOO>"}] |

  Scenario: Test get field from json response using jq
    When set the response to:
      """
      {
        "data": [
          {
            "foo":"bar"
          }
        ]
      }
      """
    And sending "POST" to "/"
    And fetch field "(FIELD_FOO)(jq).data[0].foo" from previous JSON response
    # After fetch the field, you can use the value of field like this:
    And sending "POST" to "/?foo=<FIELD_FOO>"
      | field | <data.0.foo> |
    Then the response should be a JSON array with the following mandatory values
      | key  | value             |
      | data | [{"foo":"<FIELD_FOO>"}] |

  Scenario: Test initial state with string
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-string" value="ZGVmYXVsdA==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-string" with the following values:
      """
      default
      """

  Scenario: Test initial state with string
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-string" value="InRleHQgYXMganNvbiBzdHJpbmci">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-string" with the following values:
      """
      "text as json string"
      """

  Scenario: Test initial state with boolean
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-string" value="dHJ1ZQ==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-string" with the following values:
      """
      true
      """

  Scenario: Test initial state with null
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-string" value="bnVsbA==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-string" with the following values:
      """
      null
      """

  Scenario: Test initial state with empty
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-string" value="">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-string" with the following values:
      """
      """

  Scenario: Test initial state with json
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-json-object" value="eyJmcnVpdCI6ICJvcmFuZ2UifQ==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-json-object" with the following values:
      """
      {
        "fruit": "orange"
      }
      """

  Scenario: Test initial state with array
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-json-array" value="WyJvcmFuZ2UiXQ==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-json-array" with the following values:
      """
      [
        "orange"
      ]
      """

  Scenario: Test initial state using jq
    When set the response to:
      """
      <html>
        <body>
          <input type="hidden" id="initial-state-appid-json-array" value="WyJvcmFuZ2UiXQ==">
        </body>
      </html>
      """
    And sending "POST" to "/"
    Then the response should contain the initial state "appid-json-array" json that match with:
      | key      | value  |
      | (jq).[0] | orange |

  Scenario: Test list app directory with success
    When run the bash command "ls <appRootDir>" with result code 0
    Then the output of the last command should contain the following text:
      """
      FeatureContext.php
      """

  Scenario: Test list Nextcloud directory with success
    When run the bash command "ls <nextcloudRootDir>" with result code 0

  Scenario: Test run bash command with success
    When run the bash command "true" with result code 0
    Then the output of the last command should be empty

  Scenario: Test run bash command with error
    When run the bash command "false" with result code 1

  Scenario: Run occ command with success
    When run the command "status" with result code 0

  Scenario: Run occ command with success
    When run the command "invalid-command" with result code 1

  Scenario: Create an environment with value to be used by occ command
    When create an environment "OC_PASS" with value "123456" to be used by occ command
    And run the command "fake-command" with result code 0
    Then the output of the last command should contain the following text:
      """
      I found the environment variable OC_PASS with value 123456
      """

  Scenario: Wait for seconds
    When wait for 1 seconds
    When past 1 second since wait step
