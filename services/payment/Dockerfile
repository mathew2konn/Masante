# Multi-étages : build (Gradle+JDK21 embarqués → tests unitaires exécutés) puis runtime (JRE 21).
# Aucune dépendance à la chaîne d'outils de l'hôte. Les tests unitaires (purs, sans DB/Redis)
# tournent pendant `gradle build` ; l'idempotence/intégration se prouvent en live (Postman + compose).
FROM gradle:8.10.2-jdk21 AS build
WORKDIR /home/gradle/src
COPY --chown=gradle:gradle settings.gradle build.gradle gradle.properties ./
COPY --chown=gradle:gradle src ./src
RUN gradle clean build --no-daemon

FROM eclipse-temurin:21-jre AS runtime
WORKDIR /app
RUN groupadd -r app && useradd -r -g app app
COPY --from=build /home/gradle/src/build/libs/*.jar app.jar
USER app
EXPOSE 8080
ENTRYPOINT ["java", "-jar", "app.jar"]
