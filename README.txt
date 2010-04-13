// $Id$

The Filedepot Document Management module satisfies the need for a full featured document management module supporting role or user based security. 
 - Documents can be saved outside the Drupal public directory to protect corporate documents for safe access and distribution.
 - Intuitive and convenient combination of features and modern AJAX driven Web 2.0 design provides the users with the google docs like interface
 - Flexible permission model allows you to delegate folder administration to other users. 
 - Setup, view, download and upload access for selected users or roles. Any combination of permissions can be setup per folder.
 - Integrated MS Windows desktop client is available from Nextide and allows users to easily upload one or 100's of files directly to the remote web-based document repository. 
   Simply drag and drop files from their local desktop and they are uploaded. 
   Files will appear in an Incoming Queue inside the filedepot module allowing the user to move them one at a time or in batches to their target folder.
 - Cloud Tag and File tagging support to organize and search your files. 
   Document tagging allows users to search the repository by popular tags. 
   Users can easily see what tags have been used and select one of multiple tags to display a filtered view of files in the document repository.
 - Users can upload new versions of files and the newer file will automatically be versioned. Previous versions are still available. 
   When used with the desktop client, users can have the hosted file in filedepot automatically updated each time the user saves the file - online editing.
 - Users can selectively receive notification of new files being added or changed. 
   Subscribe to individual file updates or complete folders. File owner can use the Broadcast feature to send out a personalized notification.
 - Convenient reports to view latest files, most recent folders, bookmarked files, locked or un-read files.
 - Users can flag document as 'locked' to alert users that it is being updated.

The Filedepot module is provided by Nextide www.nextide.ca and written by Blaine Lang (blainelang)


Dependencies
------------
 * Content, FileField


Install
-------

1) Copy the filedepot folder to the modules folder in your installation.

2) Enable the module using Administer -> Site building -> Modules
   (/admin/build/modules).
   
   The module will create a new content type called 'filedepot folder'
   
3) Review the module settings using Administer -> Site configuration -> Filedepot Settings
   Save your settings and at a minium, reset to defaults and save settings.
   
4) Access the module and run create some initial folders and upload files
   {siteurl}/index.php?q=filedepot
   
5) Review the permissions assigned to your site roles: {siteurl}/index.php?q=admin/user/permissions
   Users will need atleast 'access filedepot' permission to access filedepot and to view/download files.
   
Notes:
i)  You can also create new folders and upload files (attachments) via the native Drupal Content Add/View/Edit interface.
ii) A new content type is automatically created 'filedepot folder'. When adding the very first folder, the content type
    will be modified to programtically add the the CCK filefield type for the files or attachements.
    It is not possible to execute the CCK import to modify the content type during the install as the module has to be first active.
    
