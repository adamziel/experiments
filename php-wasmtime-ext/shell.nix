{ pkgs ? import <nixpkgs> {} }:

let
  php = pkgs.php82;
in pkgs.mkShell {
  buildInputs = [
    php
    php.unwrapped.dev
    pkgs.gcc
    pkgs.gnumake
    pkgs.autoconf
    pkgs.automake
    pkgs.libtool
    pkgs.pkg-config
    pkgs.curl
    pkgs.which
    pkgs.file
    pkgs.re2c
    pkgs.bison
  ];

  shellHook = ''
    export PHP_CONFIG="${php.unwrapped.dev}/bin/php-config"
    export PHPIZE="${php.unwrapped.dev}/bin/phpize"
  '';
}
