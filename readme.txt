================================================================================
                           RESUMABLE DOWNLOAD SERVER
================================================================================

                   PROVIDED AS IS. USE AT OWN RESPONSIBILITY.

--------------------------------- Requirements ---------------------------------
* PHP 5.2.x (Older PHP 5 versions might work, but some have serious file
  handling bugs.

* mod_rewrite and .htaccess control.

-------------------------------- Known Issues ----------------------------------
* Files larger than 2GB might give unexpected results on platforms that use
  32-bit integers. This is because it might affect the calculation of filesizes.

---------------------------------- Install -------------------------------------
1. Copy index.php and settings.ini to a directory on your server where you want
   your users to access the Download Server.

2. Add the following lines in your .htaccess file for the directory of your
   Download Server:

     Options +FollowSymlinks
     RewriteEngine on
     RewriteCond %{REQUEST_URI} !index\.php.*$
     RewriteRule ^(.+)$ index.php?file=$1

   This will redirect every request to the location of your Download Server to
   the Download Server script.

   Example:
   If the Download Server is located at http://download.example.com/ and someone
   makes a request; http://download.example.com/foo/bar.txt ; then your server
   will redirect this internally to
   http://download.example.com/index.php?file=foo/bar.txt

   The rewrite condition prevents redirect looping. Very important not to remove
   that line.

3. Place a collection of images representing the file types.
   The FamFamFam Silk collection is a nice free option;
   http://www.famfamfam.com/lab/icons/silk/

4. Configure server.ini to your requirements. Follow the comments in the
   provided server.ini example.

   TIP: Place the location of your file repository in a directory not accessible
        to the public if you want to force the files to go via the
        Download Server.

        Example:
        If your website is stored in the server at /home/username/html_public/
        and you install Download Server to /home/username/html_public/download/
        you can store your repository at /home/username/repository/ and point
        Download Server to this location by setting Repository in settings.ini
        to:

		  Repository = "/home/username/repository"

		or you can use path relative to your index.ini file:

		  Repository = "../../repository"

		Your files will then only be accessible via Download Server.

5. If you want to log the downloads you need to edit the log_download() function
   in index.php to fit your server and needs.

------------------------------- Version 1.1.0 ----------------------------------
Release date: 22 September 2007
Author: Thomas Thomassen
URL: http://www.thomthom.net/blog/2007/09/php-resumable-download-server/

* Serves files from a file repository.
* Makes sure no files outside the repository is served.
* Lists files if a directory is requested.
* Supports partial file request allowing users to pause or accelerate downloads.
* Logging function to keep statistic over the file downloads.
  (Needs configuring to work.)