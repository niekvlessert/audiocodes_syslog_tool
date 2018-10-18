# Audiocodes Syslog Viewer Tool by Niek Vlessert

Licensed under the GPL v3

Audiocodes SBC devices create a lot of syslog information. They actually create so much it's not easy at all to find in there what you need. It's not possible to make settings to log only the things you need. It logs SIP OPTIONS by default which will put your log full of information you will never need when troubleshooting.

This tool has been created to be able to quickly browse through Audiocodes syslog information. You can search for calls in a webgui, the information of a call will be displayed in a SIP diagram and it's easy to skip through the call.

It needs some installation work. A script has been included that should do the hard work for you. This currently works fine on CentOS 7, it's untested for Debian, Ubuntu and CentOS 6 but it should be close for those. These are the general steps done by this script:

- Install postgresql, php, rsyslog, webserver and git 
- Download the tool from Github
- Disable selinux
- Open firewall
- Let user postgres connect to the database from the terminal
- Copy the data to a directory

On a clean CentOS 7 system run the following. You will obviously end up with not the most secure setup since SELinux is disabled, improve if you desire more security...
Run this as root and make sure internet connection is available.
```
curl -O https://raw.githubusercontent.com/niekvlessert/audiocodes_syslog_tool/master/bootstrap.sh
chmod +x bootstrap.sh
./bootstrap.sh
cd /opt/ast
```

After that you must use the maintanance script that can take care of several tasks:

- Create rsyslog configuration with postgres logging settings in /etc/rsyslog.d
- Initialise the database tables
- Generate a config.php in the webdirectory for the GUI
- Create the database tables for tomorrow
- Generate a cron file

But before using it edit the settings.ini and when done run:

```
./ast_maintenance install
```

When done you must set the Audiocodes device to log syslog data to the IP address of your machine. You can then have a look in the database because data should be appearing:
```
psql -U syslog
select * from systemevents_<devicename>_<month>_<day>;
```
Be careful; every day for every SBC logging needs a few tables. If your system doesn't have them Rsyslog will fill the database log files very quickly. They cronjob in /etc/cron.d/cron_ast takes care of this.

Todo

- Search yesterday and before, only show the tables that have data
- Sorting of the results seems off sometimes
- Cronjob for deleting old data if the disk gets full
- More information about calls after initial search
- Make the calls clickable only when there's call data available
- Sometimes the database trigger goes wrong, somehow the number of fields differs
- The settings.ini contains SIP interface information settings. Those are not used yet. This will result in wrong IP addresses in the SIP dialog and might corrupt the dialog sometimes
- Display SBC errors/warnings detected in a nice searcheable way
- Add a bash completion file to be able to use tab to find correct paramaters of ast_maintenance
- Probably some (a lot?) of SIP scenarios will be parsed wrong, fix it whenever that occurs...
- The code is not very beautiful, split css/html/javascript more.
- Add some auth framework
