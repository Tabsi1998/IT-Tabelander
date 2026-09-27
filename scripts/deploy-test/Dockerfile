# A throwaway Ubuntu server for the "deploy" group of scripts/local_check.py.
# systemd runs as PID 1, so start.sh, stop.sh and update.sh meet the same
# service manager as on the real server, reboots included (docker restart).
# Node.js, Yarn and MongoDB are installed the way start.sh installs them, so
# the tests run start.sh with --no-system-install and stay fast.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      systemd systemd-sysv dbus ca-certificates curl gnupg git python3 python3-pip \
      python3-venv procps util-linux sudo psmisc \
 && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/nodesource.sh \
 && bash /tmp/nodesource.sh \
 && apt-get install -y --no-install-recommends nodejs \
 && npm install --global yarn@1.22.22 \
 && rm -rf /var/lib/apt/lists/* /tmp/nodesource.sh

RUN curl -fsSL https://pgp.mongodb.com/server-8.0.asc \
      | gpg --batch --yes --dearmor -o /usr/share/keyrings/mongodb-server-8.0.gpg \
 && echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse" \
      > /etc/apt/sources.list.d/mongodb-org-8.0.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends mongodb-org \
 && systemctl enable mongod \
 && rm -rf /var/lib/apt/lists/*

# The server user runs the project scripts and gets root rights through sudo,
# as on a typical Ubuntu server.
RUN useradd --create-home --shell /bin/bash deploy \
 && echo "deploy ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/deploy \
 && chmod 0440 /etc/sudoers.d/deploy

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
