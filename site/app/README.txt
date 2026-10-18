UPKEEP LEDGER: SELF-HOSTED SETUP
© Conrad & Claude
================================================

What this is
------------
Your own copy of the Upkeep Ledger, running on your website.
- Each person signs in with their own email and password and gets their own private ledger.
- Works on iPhone, iPad and Mac, and syncs between them.
- No Claude account and no app to install. People just open the web address.
- All data and receipt photos are stored on your hosting, in the "data" folder.

What your hosting needs
-----------------------
- PHP 7.4 or newer (8.x recommended)
- The PDO SQLite extension (on almost every cPanel host)
- An SSL certificate, so the address starts with https:// (cPanel AutoSSL is fine)


SETUP (about 10 minutes)
========================

1. Set your invite code
   Open config.php in a text editor (TextEdit is fine, or cPanel's editor after
   uploading). Change:
       const SIGNUP_CODE = 'change-me';
   to a code only you know, for example:
       const SIGNUP_CODE = 'harbour-2026';
   Anyone who wants an account needs this code. Sign-ups stay switched off until you change it.

2. Upload the files
   In cPanel open File Manager → public_html (or the folder for the site you want).
   Click "+ Folder" and create a folder, e.g.  upkeep
   Open it, click Upload, and upload the ZIP file. Back in File Manager,
   right-click the ZIP → Extract. You should end up with these files directly
   inside the folder:
       index.php  app.html  api.php  config.php  .htaccess  manifest.webmanifest
       icon-180.png  icon-192.png  icon-512.png  README.txt
   (If they land in a sub-folder, move them up one level.)
   Tip: .htaccess is hidden by default. In File Manager → Settings, tick
   "Show Hidden Files" to see it.

3. Check it's working
   Visit:   https://YOURSITE.com/upkeep/api.php?a=health
   You should see something like:
       {"ok":true,"php":"8.2.0","sqlite":true,"https":true,"signupConfigured":true}
   - "sqlite":false → cPanel → Select PHP Version → Extensions → tick pdo_sqlite
   - "ok":false     → the folder can't be written to. In File Manager, set the
                      upkeep folder's permissions to 755.
   - "https":false  → switch on SSL/AutoSSL for the domain first.

4. Create your account
   Visit https://YOURSITE.com/upkeep/
   Tap "New here? Create an account", then enter your name, email, a password
   (8+ characters) and your invite code.

5. Put it on your home screen
   iPhone: open the address in Safari → Share → Add to Home Screen.
   Mac:    open it in Safari → File → Add to Dock.

6. (Recommended) Force https
   Once https:// works, open .htaccess and remove the # from the start of the
   five lines under "Force HTTPS".


INVITING SOMEONE ELSE
=====================
Send them the address (https://YOURSITE.com/upkeep/) and your invite code.
They create their own account and get their own separate, private ledger.
Nobody can see anyone else's properties, expenses or receipts.

To stop new sign-ups, set the code back to 'change-me'. Existing people can
still sign in.


DEMO ACCOUNT
============
Anyone can tap "Try the demo" on the sign-in screen (or open yoursite/upkeep/?demo)
to use a shared demo with sample properties, stays and receipts. Changes made in
the demo are kept and sync to every device using it. Settings → "Reset demo"
puts the sample data back. To switch the demo off, or reset it every night,
change DEMO_ENABLED / DEMO_RESET_NIGHTLY in config.php.


UPDATING THE APP
================
The app itself lives in app.html and is served through index.php, so hosting
caches (CDN, StackCache, LiteSpeed) never show an old version. To update,
replace app.html (and api.php if supplied). Open copies refresh themselves.


BILLS (FIXED COSTS)
===================
Each property has a Bills section (below its entries): electricity, gas, water
and broadband month by month, and insurance, council tax, TV licence and
service charge by year. Type the amount, the dates it covers, optional units
used, and attach a photo or the PDF bill. Add your own bill types in Settings.
Bills are kept separate from expenditure and profit. Excel and PDF exports list
them with the dates they cover, and the PDF pack includes copies of the bills.

AIRBNB PAYOUTS, YEAR AT A GLANCE, REMINDERS
===========================================
Settings → Import earnings from Airbnb (CSV): download your transaction history
from Airbnb Earnings and choose the file. Each stay gets its income (and guest
name if missing). New bookings are added as stays.
Each property shows "Year at a glance": income, expenditure, profit, bills,
nights booked, occupancy and cost per night.
Email reminders (on by default, Settings to switch off): one email a month if a
regular bill is missing, and a note 3 weeks before a yearly bill ends. They are
checked once a day when anyone uses the app, and need MAIL_FROM in config.php.

AIRBNB CALENDAR IMPORT
======================
Settings → Airbnb calendar import → Connect, then paste the listing's calendar
link (Airbnb: Calendar → Availability → Connect to another website → copy link).
Bookings appear as stays and are re-checked every hour while the app is open.
Changed dates move the stay and its expenses; cancelled future bookings are
removed (or marked Cancelled if they already have expenses or income).
Airbnb doesn't share guest names or payouts, so add those to each stay.
Your hosting needs to be allowed to make outgoing https requests (almost all are).

BACKUPS
=======
Everything lives in the "data" folder:
    data/upkeep.sqlite   all accounts, properties, stays and expenses
    data/receipts/       receipt photos
    data/backups/        automatic weekly copies of the database (latest 8 kept)
To restore a backup: rename data/upkeep.sqlite to something else, copy the
backup you want into data/ and rename it upkeep.sqlite.
Each person can also download their own data from Settings → Backup.
Download that folder now and then (File Manager → right-click → Compress,
then download), or include it in your host's regular backups.
People can also export Excel/PDF files from the app at any time.

For extra privacy you can move the data outside public_html: create a folder
such as /home/YOURUSERNAME/upkeep-data, then in config.php set
    const DATA_DIR = '/home/YOURUSERNAME/upkeep-data';


SECURITY NOTES
==============
- Passwords are stored hashed (never in plain text).
- The data folder is blocked from web access by .htaccess.
- Each receipt photo can only be opened by the person who uploaded it.
- Repeated wrong passwords are blocked for 15 minutes.
- Forgotten passwords: tap "Forgot password?" on the sign-in screen. A reset
  link is emailed (valid for 1 hour). This needs MAIL_FROM set in config.php.


TROUBLESHOOTING
===============
"The server didn't respond properly"  → api.php is missing or PHP isn't running
                                         for that folder. Re-check step 2 and 3.
Can't sign in on iPhone after adding to home screen → open it in Safari once
                                         and sign in there, then re-add it.
Export says the tools couldn't load   → the exports load two small libraries
                                         from cdnjs.cloudflare.com, so you need
                                         an internet connection.
