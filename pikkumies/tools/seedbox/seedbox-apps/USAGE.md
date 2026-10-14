# seedbox-apps

A procedure, not a tool: how to check, restart and set up the user's seedbox apps, starting from the Pulsed Media wiki page for the app.

```sh
php ~/pikkumies/tools/system/system-status/system-status.php show        # is this PMSS, which apps run
php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "<page title>"   # find the wiki page
php ~/pikkumies/tools/memory/pikkumies/pikkumies.php list pm-wiki --human | grep -i <app>   # every wiki page title
```

Changes nothing by itself; any restart it describes is done the way the platform starts that app for this user.
