#!/bin/bash
# Build the Slackware-style package + update MD5 in shive.plg.
#   ./build.sh [version]     (default: today, YYYY.MM.DD)
set -euo pipefail
VER="${1:-$(date +%Y.%m.%d)}"
PKG="shive-${VER}-noarch-1.txz"
rm -rf build && mkdir -p build/pkg && cp -a src/. build/pkg/
mkdir -p build/pkg/install
cat > build/pkg/install/slack-desc <<'D'
shive: shive (ZFS snapshot scheduling, retention & replication for Unraid)
shive:
shive: Generic ZFS snapshot schedules with GFS/age retention, optional Docker
shive: quiesce/resume, local + SSH replication, snapshot browser/restore.
D
chmod 0755 build/pkg/usr/local/emhttp/plugins/shive/scripts/shive-* build/pkg/usr/local/emhttp/plugins/shive/event/*
# Reproducible: identical sources must produce an identical package, because the MD5 that ends up
# in the committed shive.plg has to match the package the release attaches. Without pinning sort
# order, mtimes and ownership, two builds of the same tree differ and the checksum is meaningless.
( cd build/pkg && tar --sort=name --mtime="@0" --owner=0 --group=0 --numeric-owner -cJf "../$PKG" . )
MD5=$(md5sum "build/$PKG" | cut -d' ' -f1)
sed -i -e "s/<!ENTITY version   \"[^\"]*\">/<!ENTITY version   \"$VER\">/" \
       -e "s/<!ENTITY pkgMD5    \"[^\"]*\">/<!ENTITY pkgMD5    \"$MD5\">/" shive.plg
xmllint --noout shive.plg 2>/dev/null || { echo "shive.plg is not well-formed XML"; exit 1; }
# Local variant: reads the package from the flash drive instead of a GitHub release.
# It MUST be named shive.plg: update_cron derives the directories it scans from the symlink
# names in /var/log/plugins, so a "shive-local.plg" would make it look in
# /boot/config/plugins/shive-local/ and never find our shive.cron.
mkdir -p build/local
sed "s#<URL>.*</URL>#<URL>file:///boot/config/plugins/shive/$PKG</URL>#" shive.plg > "build/local/shive.plg"
echo "built build/$PKG  md5=$MD5  (shive.plg updated, XML validated)"
echo "Local test install on the Unraid box:"
echo "  mkdir -p /boot/config/plugins/shive && cp build/$PKG /boot/config/plugins/shive/"
echo "  cp build/local/shive.plg /boot/config/plugins/"
echo "  plugin install /boot/config/plugins/shive.plg"
