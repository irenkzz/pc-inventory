Bundled smartmontools files for SSD SMART/TBW collection.

Source:
- smartmontools 7.5 Windows package
- installed via winget package smartmontools.smartmontools
- upstream release URL: https://github.com/smartmontools/smartmontools/releases/tag/RELEASE_7_5

Bundled files:
- smartctl.exe
- drivedb.h
- COPYING.smartmontools.txt

License:
- GNU General Public License version 2.0

Operational note:
- The runner uses this bundled smartctl first.
- If the disk/controller does not expose SMART/NVMe counters, TBW fields remain blank.
