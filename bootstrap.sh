#!/bin/bash
RESULT=$(which yum > /dev/null 2>&1)
NO_YUM=$?

RESULT=$(which apt-get > /dev/null 2>&1)
NO_APT=$?

if (( $NO_YUM == 1 && $NO_APT == 1));
then
	echo "No Yum or Apt detected, your system is unsupported... please install the required packages manually."
	exit
fi

if (( $NO_YUM == 0 ));
then
	yum -y install postgresql php php-pgsql rsyslog rsyslog-pgsql apache2 postgresql-server mlocate git
	if (( $? == 1 ));
	then
		echo "Some error occured, please check and run the script again..."
		exit
	fi
fi

if (( $NO_APT == 0 ));
then
	apt-get install postgresql php php-pgsql rsyslog rsyslog-pgsql apache2 postgresql-server mlocate git
	if (( $? == 1 ));
	then
		echo "Some error occured, please check and run the script again..."
		exit
	fi
fi

echo
echo

echo "Cloning Audiocodes Syslog Tool..."
git clone https://github.com/niekvlessert/audiocodes_syslog_tool
echo "Basic initialisation of Postgres..."
#service postgresql initdb
postgresql-setup initdb
echo "Allow short open tag in /etc/php.ini..."
sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php.ini
echo "Allow postgres connections more loosely..."
sed -i "s/\#listen_addresses = 'localhost'*/listen_addresses = '\*'        /g" /var/lib/pgsql/data/postgresql.conf
sed -i 's/host    all             all             127.0.0.1\/32            ident/host    all             all             127.0.0.1\/32            trust/g' /var/lib/pgsql/data/pg_hba.conf

RESULT=$(which systemctl > /dev/null 2>&1)
if (( $? == 0 ));
then
	echo "Restarting postgres"
	systemctl restart postgresql
else
	/etc/init.d/postgresql restart
fi
if (( $NO_YUM == 0 ));
then
	chkconfig postgresql on
fi

echo "Adding a line to the IPTables to allow syslog information on UDP port 514..."
iptables -D INPUT -p udp --dport 514 -j ACCEPT > /dev/null 2>&1
iptables -I INPUT -p udp --dport 514 -j ACCEPT
iptables-save > /dev/null 2>&1

cd audiocodes_syslog_tool
php maintenance.php install
