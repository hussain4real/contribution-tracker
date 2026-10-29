"""Extract the matching PHP SQLite module without installing/upgrading host packages."""
import hashlib
import json
import subprocess
from pathlib import Path

root=Path('/opt/familyfunds-d1-php')
root.mkdir(exist_ok=True)
version=subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True)
api=Path(subprocess.check_output(['php','-r','echo PHP_EXTENSION_DIR;'],text=True)).name
module=root/'usr/lib/php'/api/'pdo_sqlite.so'
if not module.exists():
    download=root/'download';download.mkdir(exist_ok=True)
    subprocess.run(['apt-get','download','php8.4-sqlite3'],cwd=download,check=True,stdout=subprocess.DEVNULL)
    packages=list(download.glob('php8.4-sqlite3_*.deb'))
    if len(packages)!=1:raise RuntimeError('Expected one isolated module package')
    package=packages[0]
    package_version=subprocess.check_output(['dpkg-deb','-f',str(package),'Version'],text=True).strip()
    if not package_version.startswith(version+'-'):raise RuntimeError('Refusing a module from another PHP patch version')
    subprocess.run(['dpkg-deb','--extract',str(package),str(root)],check=True)
flags=['-d','extension='+str(module),'-d','extension='+str(module.with_name('sqlite3.so'))]
drivers=json.loads(subprocess.check_output(['php',*flags,'-r','echo json_encode(PDO::getAvailableDrivers());'],text=True))
if 'sqlite' not in drivers:raise RuntimeError('Isolated SQLite driver did not load')
wrapper=root/'php';wrapper.write_text('#!/bin/sh\nexec /usr/bin/php '+ ' '.join(flags)+' "$@"\n');wrapper.chmod(0o755)
unit=Path('/etc/systemd/system/familyfunds-d1-staging.service')
content=unit.read_text();content=content.replace('ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize','ExecStart=/usr/sbin/php-fpm8.4 '+' '.join(flags)+' --nodaemonize')
unit.write_text(content)
subprocess.run(['/usr/sbin/php-fpm8.4',*flags,'--test','--fpm-config','/etc/php/8.4/fpm/d1-staging.conf'],check=True)
subprocess.run(['systemctl','daemon-reload'],check=True)
subprocess.run(['systemctl','restart','familyfunds-d1-staging'],check=True)
print(json.dumps({'php_version':version,'pdo_drivers_isolated':drivers,'module_sha256':hashlib.sha256(module.read_bytes()).hexdigest(),'host_packages_installed_or_upgraded':False,'production_fpm_restarted':False}))
