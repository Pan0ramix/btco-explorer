FROM python:3.12-slim-bookworm AS build
RUN apt-get update && apt-get install -y --no-install-recommends git patch build-essential libleveldb-dev
RUN git clone https://github.com/spesmilo/electrumx.git /src \
    && git -C /src checkout --detach b267f3c5ff72f5645bfb40f1da1b7508bf7a6883
COPY bitcoinoil.patch /tmp/
RUN cd /src && patch -p1 < /tmp/bitcoinoil.patch \
    && python -m venv /opt/electrumx \
    && /opt/electrumx/bin/pip install 'plyvel==1.5.1' .

FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libleveldb1d \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10001 electrumx \
    && install -d -o electrumx /data
COPY --from=build /opt/electrumx /opt/electrumx
USER electrumx
ENTRYPOINT ["/opt/electrumx/bin/electrumx_server"]
