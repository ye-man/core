@webUI @insulated @disablePreviews @systemtags-app-required
Feature: Deletion of existing tags from files and folders
  As a user
  I want to delete tags from files and folders
  So that I can keep my filing system clean and tidy

  Background:
    Given these users have been created with default attributes:
      | username |
      | user1    |
      | user2    |
    And the user has browsed to the login page
    And the user has logged in with username "user1" and password "%alt1%" using the webUI

@skipOnFIREFOX
  Scenario: Delete a tag in a shared file
    When the user renames file "lorem.txt" to "coolnewfile.txt" using the webUI
    And the user browses directly to display the details of file "coolnewfile.txt" in folder ""
    And the user adds a tag "tag1" to the file using the webUI
    And the user shares file "coolnewfile.txt" with user "User Two" using the webUI
    And the user re-logs in with username "user2" and password "%alt2%" using the webUI
    Then file "coolnewfile.txt" should have the following tags for user "user2"
    | tag1 | normal |
    When the user browses directly to display the details of file "coolnewfile.txt" in folder ""
    And the user deletes tag with name "tag1" using the webUI
    Then tag "tag1" should not exist for user "user1"
    And tag "tag1" should not exist for user "user2"

  Scenario: Delete a tag that already exists for a file in the root
    Given the user has created a "normal" tag with name "lorem"
    And the user has added tag "lorem" to file "lorem.txt"
    When the user browses directly to display the details of file "lorem.txt" in folder "/"
    Then file "lorem.txt" should have the following tags for user "user1"
      | lorem | normal |
    When the user deletes tag with name "lorem" using the webUI
    Then tag "lorem" should not exist for user "user1"

  Scenario: Delete a tag that already exists for a file in a folder
    Given the user has created a "normal" tag with name "lorem"
    And the user has added tag "lorem" to file "simple-folder/lorem.txt"
    When the user browses directly to display the details of file "lorem.txt" in folder "simple-folder"
    Then file "simple-folder/lorem.txt" should have the following tags for user "user1"
      | lorem | normal |
    When the user deletes tag with name "lorem" using the webUI
    Then tag "lorem" should not exist for user "user1"
