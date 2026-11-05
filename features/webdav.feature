Feature: Generic Nextcloud WebDAV steps
  Scenario: Route WebDAV paths directly and encode file names
    When user "test" sends WebDAV "DELETE" to "Documents/Report 2026.pdf"
    Then the response should have a status code "200"

  Scenario: Read WebDAV properties using standard Nextcloud and DAV namespaces
    Given set the WebDAV response to:
      """
      <?xml version="1.0"?>
      <d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns">
        <d:response>
          <d:propstat>
            <d:prop>
              <nc:fileid>123</nc:fileid>
              <d:getetag>sample-etag</d:getetag>
              <oc:permissions>RW</oc:permissions>
            </d:prop>
          </d:propstat>
        </d:response>
      </d:multistatus>
      """
    When user "admin" sends WebDAV "PROPFIND" to "Example/document.pdf"
      """
      <d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>
      """
    Then the response should have a status code "207"
    And the WebDAV response should contain property "fileid" with value "123"
    And the WebDAV response should contain property "d:getetag" with value "sample-etag"
    And the WebDAV response should contain property "oc:permissions" with value "RW"
    And fetch WebDAV property "fileid" to "FILE_ID"
    And sending "GET" to "/?fileId=<FILE_ID>"

  Scenario: Send raw content to WebDAV without form encoding
    When user "test" sends WebDAV "PUT" to "Documents/request.txt"
      """
      text-content-123
      """
    Then the response should have a status code "200"
