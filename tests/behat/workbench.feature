@local @local_contenttranslator @javascript
Feature: Translation dashboard, editor and settings
  In order to review AI translations of course content
  As a teacher or manager
  I need a dashboard, a side-by-side editor and course settings

  Background:
    Given the following "courses" exist:
      | fullname | shortname | summary                    |
      | Course 1 | C1        | <p>Summary of course one</p> |
    And the following "activities" exist:
      | activity | course | name     | content                 |
      | page     | C1     | Page one | <p>Body of page one</p> |
    And the following config values are set as admin:
      | targetlangs | de      | local_contenttranslator |
      | engine      | pseudo  | local_contenttranslator |
      | budgetchars | 1000000 | local_contenttranslator |

  Scenario: Scan a course, translate on demand, review and lock
    Given I log in as "admin"
    And I am on "Course 1" course homepage
    When I navigate to "Translations" in current page administration
    Then I should see "Translation dashboard"
    When I press "Scan content"
    Then I should see "Scan finished"
    And I should see "Page one"
    When I click on "Missing" "link" in the "Body of page one" "table_row"
    Then I should see "Translation editor"
    And I should see "Body of page one"
    When I click on "Translate now" "link"
    Then I should see "Translated."
    And I should see "pseudo"
    When I press "Save & mark reviewed"
    Then I should see "Translation saved."
    And I should see "Reviewed"
    And I should see "[de] Body of page one"
    When I click on "Lock" "link"
    Then I should see "Locked"

  Scenario: Course translation settings and the setup wizard
    Given I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Translations" in current page administration
    When I press "Course translation settings"
    Then I should see "Automatic translation"
    When I set the field "Automatic translation" to "No"
    And I press "Save changes"
    Then I should see "Changes saved"
    When I navigate to "Plugins > Local plugins > Content translator > Setup wizard" in site administration
    Then I should see "Monthly budget"
    And the field "Monthly budget (characters)" matches value "1000000"
