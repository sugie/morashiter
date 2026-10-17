FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY html/ /usr/share/nginx/html/

RUN addgroup -S fixture && adduser -S -G fixture fixture \
    && chown -R fixture:fixture /usr/share/nginx/html

EXPOSE 80
