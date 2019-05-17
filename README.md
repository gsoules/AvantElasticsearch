# AvantElasticsearch

Provides the [AvantSeach](https://github.com/gsoules/AvantSearch) plugin with access to an Elasticsearch service hosted on Amazon AWS.
     
## Dependencies
The AvantElasticsearch plugin requires that the [AvantCommon](https://github.com/gsoules/AvantCommon) plugin be installed and activated.

This plugin was developed specifically for [Digital Archive](http://thedigitalarchive.net/) installations. It has special
knowledge of the following Omeka elements and how they are used in these installations.
* Type
* Subject
* Place
* Address
* Date

This AvantElaticsearch plugin works only with Elasticsearch on Amazon Web Services (AWS).

The host Linux OS must have pdftotext installed.

The Omeka files folder must contain a directory named 'elasticsearch'.

## Installation

To install the AvantElasticsearch plugin, follow these steps:

1. First install and activate the [AvantCommon](https://github.com/gsoules/AvantCommon) plugin.
1. Unzip the AvantElasticsearch-master file into your Omeka installation's plugin directory.
1. Rename the folder to AvantElasticsearch.
1. Activate the plugin from the Admin → Settings → Plugins page.
1. Configure the plugin to provide access to and [Signature v4](https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html)
credentials for your [Amazon Elasticsearch Service](https://aws.amazon.com/elasticsearch-service/).

## Warning

Use this software at your own risk.

##  License

This plugin is published under [GNU/GPL].

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

## Copyright

* Created by [gsoules](https://github.com/gsoules) based on code from Harvard University.
* Copyright George Soules, 2019.
* See [LICENSE](https://github.com/gsoules/AvantSearch/blob/master/LICENSE) for more information.


## Credits
The author wishes to thank the Harvard Academic Technology Group at Harvard University, developers of the
[Elasticsearch](https://github.com/Harvard-ATG/omeka-plugin-Elasticsearch) v1.1.1 plugin for Omeka Classic.
It provided helpful insight in how integrate Elasticsearch with Omeka.





