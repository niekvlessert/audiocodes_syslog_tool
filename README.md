# Audiocodes Syslog Viewer Tool

Audiocodes SBC devices create a lot of syslog information. They actually create so much it's not easy at all to find in there what you need. It's not possible to make settings to log only the things you need. It logs SIP OPTIONS by default which will put your log full of useless information.

This tool has been created to be able to quickly browse through Audiocodes syslog information. You can search for calls in a webgui, the information of a call will be displayed in a SIP diagram and it's easy to skip through the call.

It needs some installation work. A script has been included to do some of the hard work for you. These are the general steps:

- install postgresql, php, rsyslog, and your webserver of choice
- config rsyslog to listen on udp and enable the postgresql module
- disable selinux/firewall
- let user postgres connect to the database from the terminal
- enter IP addresses from where syslog can be expected in the config file
- generate rsyslog configuration files by script and copy them to the right place
- install database structure using script

On CentOS 6 it boils down to something like this after a clean installation. You will obviously end up with not the most secure setup, improve if you desire more security...

```
yum install postgresql php php-pgsql rsyslog rsyslog-pgsql apache2 postgresql-server mlocate git
cd
git clone https://github.com/niekvlessert/audiocodes_syslog_tool
cd audiocodes_syslog_tool
service postgresql initdb
vi /etc/php.ini #set short_open_tag to on
setenforce 0 #disable SELinux on the current session. Change enforcing in disabled in /etc/selinux/config and reboot for permanent disable
vi /var/lib/pgsql/data/postgresql.conf #change listen_addresses to * and enable the line
vi /var/lib/pgsql/data/pg_hba.conf #in the bottom lines change ident to trust, to allow passwordless connections
vi /etc/rsyslog.conf # enable $ModLoad imudp and $UDPServerRun 514, add $ModLoad ompgsql
service postgresql restart
service httpd start
chkconfig --add postgresql
chkconfig --add httpd
iptables -F
iptables-save
vi config.php #Add one or more devices which will send syslog data in the array $devices_to_log
php maintenance.php initializeDatabase
php maintenance.php generateRsyslogConfig
cp 00_audiocodes.conf /etc/rsyslog.d
service rsyslog restart
```

Now setup the Audiocodes device to log syslog data to the IP address of your machine and have a look in the database because data should be appearing with:
```
psql -U syslog
select * from systemevents_<devicename>;
```
To delete the option data pooring in a cronjob is needed. It's also required to vacuum the tables to avoid wasting disk space. And the tables need to be rotated; one table for every day.
So add the following cronjobs witch `crontab -e`
```
0 0 * * * /usr/bin/php ~/audiocodes_syslog_tool/maintenance.php tableRotate
2 * * * * /usr/bin/php ~/audiocodes_syslog_tool/maintenance.php deleteOptionRecords
5 * * * * /usr/bin/php ~/audiocodes_syslog_tool/maintenance.php vacuumCurrentTables
```
Now all data needed for the GUI should be coming in. Copy the following files to the root directory of the webserver:
```
cp config.php /var/www/html
cp index.php /var/www/html
```
Now visit your webserver address and have a look.

Usage

Document later...

Todo

- Probably some (a lot?) of SIP scenarios will be parsed wrong, fix it whenever that occurs...
- The code is not very beautiful, split css/html/javascript more.
- https://linuxgazette.net/172/peterson.html; make RSyslog buffer the output headed for the database
- Add some auth framework
