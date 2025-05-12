Generate (module for Omeka S)
=============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Generate] is a module for [Omeka S] that allows to fill items metadata from
values generated from medias by an external tool. Currently, one generator is
implemented, [ChatGPT]. Generated metadata can be validated by a reviewer.


Installation
------------

### Module

See general end user documentation for [installing a module].

The module [Common] must be installed first.

* From the zip

Download the last release [Generate.zip] from the list of releases (the master
does not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Generate`.


Usage
-----

To generate metadata of an item, it should have a template and at least one file
attached as media.

- Set the [ChatGPT] api key in the config form.

### Process on save

- Check the box in the tab "Advanced" of the item form.
- An automatic prompt is available with properties of the resource template.
  Else, you can configure a specific prompt.

### Bulk process

- Select items to process via an advanced search, then batch edit them.
- In the batch edit form, an automatic prompt is available with properties of
  the resource template. Else, you can configure a specific prompt.
- After process, the admin can go to the resource page of the edited items and
  moderate the values. The values can be accepted as a whole or one by one.
- A page lists lists all generations too. Generation can be marked as reviewed.


TODO
----

- [ ] Add an option to skip validation (so a radio instead of a checkbox in forms).
- [ ] Add an option to set a value annotation like "generated". For now, it can be set via module Advanced Resource Template.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2019-2025 (see [Daniel-KM] on GitLab)

First version of this module was done for [Université de Paris-Saclay].
Improvements were done for [Enssib] and for the site used to do the deposit and
the digital archiving of student works ([Dante]) of the [Université de Toulouse Jean-Jaurès].


[Omeka S]: https://omeka.org/s
[Generate]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generate
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Generate.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generate/-/releases
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generate/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[ChatGPT]: https://chatgpt.com
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
