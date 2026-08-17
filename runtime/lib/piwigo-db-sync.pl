#!/usr/bin/perl

use strict;
use warnings;
use JSON::PP qw(decode_json);
use LWP::UserAgent;
use Getopt::Long;

my %opt;
GetOptions(
    \%opt,
    'base-url=s',
    'username=s',
    'password-file=s',
) or die "Ungültige Parameter\n";

for my $required (qw(base-url username password-file)) {
    die "Parameter --$required fehlt\n" if !defined $opt{$required} || $opt{$required} eq '';
}

open my $password_handle, '<', $opt{'password-file'}
    or die "Passwortdatei kann nicht gelesen werden: $!\n";
my $password = <$password_handle>;
close $password_handle;
defined $password or die "Passwortdatei ist leer\n";
chomp $password;

my $ua = LWP::UserAgent->new(timeout => 900);
$ua->agent('Bratonien-NC-Connector/1.0');
$ua->cookie_jar({});

my $login = $ua->post(
    $opt{'base-url'}.'/ws.php?format=json',
    {
        method => 'pwg.session.login',
        username => $opt{username},
        password => $password,
    },
);
die "Piwigo-Anmeldung fehlgeschlagen: ".$login->status_line."\n"
    if !$login->is_success;

my $login_result = eval { decode_json($login->decoded_content) };
die "Piwigo-Anmeldung wurde abgelehnt\n"
    if !$login_result || ($login_result->{stat} // '') ne 'ok';

my $sync = $ua->post(
    $opt{'base-url'}.'/admin.php?page=site_update&site=1',
    {
        sync => 'files',
        display_info => 1,
        privacy_level => 0,
        sync_meta => 1,
        simulate => 0,
        'subcats-included' => 1,
        submit => 1,
    },
);
die "Piwigo-Datenbanksynchronisierung fehlgeschlagen: ".$sync->status_line."\n"
    if !$sync->is_success;

my $orphans = $ua->post(
    $opt{'base-url'}.'/ws.php?format=json',
    {
        method => 'bratonien.nc.syncOrphans',
        site_id => 1,
        simulate => 0,
    },
);
die "Piwigo-Orphan-Synchronisierung fehlgeschlagen: ".$orphans->status_line."\n"
    if !$orphans->is_success;

my $orphan_result = eval { decode_json($orphans->decoded_content) };
die "Piwigo-Orphan-Synchronisierung wurde abgelehnt\n"
    if !$orphan_result || ($orphan_result->{stat} // '') ne 'ok';

my $result = $orphan_result->{result} || {};
my $added = $result->{added_orphans} // 0;
my $deleted = $result->{deleted_orphans} // 0;

print "Piwigo-Datenbanksynchronisierung erfolgreich\n";
print "Piwigo-Orphans synchronisiert: +$added / -$deleted\n";
