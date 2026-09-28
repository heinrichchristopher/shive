#!/bin/bash
# Build the Slackware-style package and set version + MD5 in shive.plg.
#   ./build.sh [version]     (default: today, YYYY.MM.DD; same-day re-releases: YYYY.MM.DD.1, .2, ...)
#
# Needs GNU/Linux tools (md5sum, GNU sed, xmllint, python3). On macOS run it in the pinned
# container from README.md ("Releasing"). The package is uploaded to the GitHub release exactly as
# built here, so the MD5 in shive.plg belongs to the published file by construction.
set -euo pipefail
for tool in python3 md5sum xmllint; do
  command -v "$tool" >/dev/null 2>&1 || { echo "build.sh needs '$tool' - run it in the container (see README.md, Releasing)"; exit 1; }
done
VER="${1:-$(date +%Y.%m.%d)}"
PKG="shive-${VER}-noarch-1.txz"
rm -rf build && mkdir -p build/pkg && cp -a src/. build/pkg/
# This packs the working directory, not git: never ship OS metadata a checkout on a Mac may hold.
find build/pkg \( -name '.DS_Store' -o -name '._*' \) -delete
mkdir -p build/pkg/install
cat > build/pkg/install/slack-desc <<'D'
shive: shive (ZFS snapshot scheduling, retention & replication for Unraid)
shive:
shive: Generic ZFS snapshot schedules with GFS/age retention, optional Docker
shive: quiesce/resume, local + SSH replication, snapshot browser/restore.
D
chmod 0755 build/pkg/usr/local/emhttp/plugins/shive/scripts/shive-* build/pkg/usr/local/emhttp/plugins/shive/event/*
# Deterministic package: entries are added in sorted order with mtime/uid/gid/uname/gname zeroed and
# the xz stream is produced with fixed parameters. The tar is built with Python's tarfile module
# instead of the platform's tar binary (bsdtar on macOS does not know GNU tar's --sort). Identical
# sources give identical bytes with the same interpreter/liblzma, i.e. inside the pinned container.
# Different liblzma builds can still produce different bytes, which is why the release process
# never rebuilds the package on another machine (see docs/ARCHITECTURE.md).
python3 - "$PKG" <<'PYEOF'
import tarfile, os, sys, lzma, io
pkg = sys.argv[1]
# tarfile.open(mode="w:xz") leaves the compression preset/check to lzma's own defaults, which are
# NOT guaranteed identical across Python/liblzma builds (macOS's bundled liblzma vs. Ubuntu's) even
# for byte-identical input - the exact failure mode this reproducibility fix was supposed to close.
# Pin every parameter explicitly so the compressed output depends only on the input bytes.
filters = [{"id": lzma.FILTER_LZMA2, "preset": 6}]
buf = io.BytesIO()
with tarfile.open(fileobj=buf, mode="w") as tf:
    for root, dirs, files in os.walk("build/pkg"):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(root, name)
            arcname = "." + path[len("build/pkg"):]
            info = tf.gettarinfo(path, arcname=arcname)
            info.mtime = 0; info.uid = 0; info.gid = 0; info.uname = ""; info.gname = ""
            with open(path, "rb") as f:
                tf.addfile(info, f)
with open(f"build/{pkg}", "wb") as out:
    out.write(lzma.compress(buf.getvalue(), format=lzma.FORMAT_XZ, check=lzma.CHECK_CRC32, filters=filters))
PYEOF
MD5=$(md5sum "build/$PKG" | cut -d' ' -f1)
sed -i -e "s/<!ENTITY version   \"[^\"]*\">/<!ENTITY version   \"$VER\">/" \
       -e "s/<!ENTITY pkgMD5    \"[^\"]*\">/<!ENTITY pkgMD5    \"$MD5\">/" shive.plg
xmllint --noout shive.plg || { echo "shive.plg is not well-formed XML"; exit 1; }
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
